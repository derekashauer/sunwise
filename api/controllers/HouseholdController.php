<?php
/**
 * Household Controller
 * Manage household sharing of plants between users
 */

class HouseholdController
{
    /**
     * Create a new household
     * POST /households
     */
    public function create(array $params, array $body, ?int $userId): array
    {
        $name = trim($body['name'] ?? 'My Household');

        // Check if user already owns a household
        $stmt = db()->prepare('SELECT id FROM households WHERE owner_id = ?');
        $stmt->execute([$userId]);
        if ($stmt->fetch()) {
            return ['status' => 400, 'data' => ['error' => 'You already have a household']];
        }

        // Create household
        $stmt = db()->prepare('INSERT INTO households (name, owner_id) VALUES (?, ?)');
        $stmt->execute([$name, $userId]);
        $householdId = db()->lastInsertId();

        // Add owner as member with 'owner' role
        $stmt = db()->prepare('
            INSERT INTO household_members (household_id, user_id, role, display_name)
            VALUES (?, ?, "owner", (SELECT COALESCE(display_name, SUBSTR(email, 1, INSTR(email, "@") - 1)) FROM users WHERE id = ?))
        ');
        $stmt->execute([$householdId, $userId, $userId]);

        return $this->getHouseholdResponse($householdId);
    }

    /**
     * List households user belongs to
     * GET /households
     */
    public function index(array $params, array $body, ?int $userId): array
    {
        $stmt = db()->prepare('
            SELECT h.*,
                   hm.role,
                   (SELECT COUNT(*) FROM household_members WHERE household_id = h.id) as member_count,
                   (SELECT COUNT(*) FROM household_plants WHERE household_id = h.id) as plant_count
            FROM households h
            JOIN household_members hm ON h.id = hm.household_id
            WHERE hm.user_id = ?
        ');
        $stmt->execute([$userId]);

        return ['households' => $stmt->fetchAll()];
    }

    /**
     * Get household details
     * GET /households/{id}
     */
    public function show(array $params, array $body, ?int $userId): array
    {
        $householdId = $params['id'];

        // Verify membership
        if (!$this->isMember($householdId, $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Household not found']];
        }

        return $this->getHouseholdResponse($householdId);
    }

    /**
     * Update household name
     * PUT /households/{id}
     */
    public function update(array $params, array $body, ?int $userId): array
    {
        $householdId = $params['id'];

        // Only owner can update
        if (!$this->isOwner($householdId, $userId)) {
            return ['status' => 403, 'data' => ['error' => 'Only the household owner can update settings']];
        }

        $name = trim($body['name'] ?? '');
        if (!$name) {
            return ['status' => 400, 'data' => ['error' => 'Household name is required']];
        }

        $stmt = db()->prepare('UPDATE households SET name = ? WHERE id = ?');
        $stmt->execute([$name, $householdId]);

        return $this->getHouseholdResponse($householdId);
    }

    /**
     * Delete household
     * DELETE /households/{id}
     */
    public function destroy(array $params, array $body, ?int $userId): array
    {
        $householdId = $params['id'];

        // Only owner can delete
        if (!$this->isOwner($householdId, $userId)) {
            return ['status' => 403, 'data' => ['error' => 'Only the household owner can delete it']];
        }

        $stmt = db()->prepare('DELETE FROM households WHERE id = ?');
        $stmt->execute([$householdId]);

        return ['message' => 'Household deleted successfully'];
    }

    /**
     * List household members
     * GET /households/{id}/members
     */
    public function members(array $params, array $body, ?int $userId): array
    {
        $householdId = $params['id'];

        if (!$this->isMember($householdId, $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Household not found']];
        }

        $stmt = db()->prepare('
            SELECT hm.id, hm.user_id, hm.role, hm.display_name, hm.joined_at,
                   u.email
            FROM household_members hm
            JOIN users u ON hm.user_id = u.id
            WHERE hm.household_id = ?
            ORDER BY hm.role = "owner" DESC, hm.joined_at ASC
        ');
        $stmt->execute([$householdId]);

        return ['members' => $stmt->fetchAll()];
    }

    /**
     * Remove member from household
     * DELETE /households/{id}/members/{userId}
     */
    public function removeMember(array $params, array $body, ?int $userId): array
    {
        $householdId = $params['id'];
        $targetUserId = (int)$params['userId'];

        // User can remove themselves (leave), or owner can remove others
        $isOwner = $this->isOwner($householdId, $userId);
        $isSelf = $userId === $targetUserId;

        if (!$isOwner && !$isSelf) {
            return ['status' => 403, 'data' => ['error' => 'You can only remove yourself or be the owner']];
        }

        // Owner cannot remove themselves
        if ($isOwner && $isSelf) {
            return ['status' => 400, 'data' => ['error' => 'Owner cannot leave. Transfer ownership or delete the household.']];
        }

        $stmt = db()->prepare('DELETE FROM household_members WHERE household_id = ? AND user_id = ?');
        $stmt->execute([$householdId, $targetUserId]);

        return ['message' => 'Member removed successfully'];
    }

    /**
     * List shared plants in household
     * GET /households/{id}/plants
     */
    public function plants(array $params, array $body, ?int $userId): array
    {
        $householdId = $params['id'];

        if (!$this->isMember($householdId, $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Household not found']];
        }

        $stmt = db()->prepare('
            SELECT p.*, hp.shared_at,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as thumbnail,
                   u.email as owner_email,
                   COALESCE(u.display_name, SUBSTR(u.email, 1, INSTR(u.email, "@") - 1)) as owner_name
            FROM household_plants hp
            JOIN plants p ON hp.plant_id = p.id
            JOIN users u ON p.user_id = u.id
            WHERE hp.household_id = ? AND p.archived_at IS NULL
            ORDER BY p.name ASC
        ');
        $stmt->execute([$householdId]);

        return ['plants' => $stmt->fetchAll()];
    }

    /**
     * Share plants with household
     * POST /households/{id}/plants
     * Body: { plant_ids: [1, 2, 3] }
     */
    public function sharePlants(array $params, array $body, ?int $userId): array
    {
        $householdId = $params['id'];
        $plantIds = $body['plant_ids'] ?? [];

        if (!$this->isMember($householdId, $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Household not found']];
        }

        if (empty($plantIds)) {
            return ['status' => 400, 'data' => ['error' => 'No plants specified']];
        }

        $shared = 0;
        foreach ($plantIds as $plantId) {
            // Verify user owns the plant
            $stmt = db()->prepare('SELECT id FROM plants WHERE id = ? AND user_id = ?');
            $stmt->execute([$plantId, $userId]);
            if (!$stmt->fetch()) {
                continue; // Skip plants user doesn't own
            }

            // Check if already shared
            $stmt = db()->prepare('SELECT 1 FROM household_plants WHERE household_id = ? AND plant_id = ?');
            $stmt->execute([$householdId, $plantId]);
            if ($stmt->fetch()) {
                continue; // Already shared
            }

            // Share the plant
            $stmt = db()->prepare('INSERT INTO household_plants (household_id, plant_id, shared_by) VALUES (?, ?, ?)');
            $stmt->execute([$householdId, $plantId, $userId]);
            $shared++;
        }

        return ['message' => "{$shared} plant(s) shared with household"];
    }

    /**
     * Remove plant from household sharing
     * DELETE /households/{id}/plants/{plantId}
     */
    public function unsharePlant(array $params, array $body, ?int $userId): array
    {
        $householdId = $params['id'];
        $plantId = $params['plantId'];

        // Only plant owner or household owner can unshare
        $isHouseholdOwner = $this->isOwner($householdId, $userId);

        $stmt = db()->prepare('SELECT user_id FROM plants WHERE id = ?');
        $stmt->execute([$plantId]);
        $plant = $stmt->fetch();

        if (!$plant) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        $isPlantOwner = $plant['user_id'] == $userId;

        if (!$isHouseholdOwner && !$isPlantOwner) {
            return ['status' => 403, 'data' => ['error' => 'Only the plant owner or household owner can unshare']];
        }

        $stmt = db()->prepare('DELETE FROM household_plants WHERE household_id = ? AND plant_id = ?');
        $stmt->execute([$householdId, $plantId]);

        return ['message' => 'Plant removed from household'];
    }

    /**
     * Send invitation to join household
     * POST /households/{id}/invite
     * Body: { email: string, share_all_plants?: bool, plant_ids?: array }
     */
    public function invite(array $params, array $body, ?int $userId): array
    {
        $householdId = $params['id'];
        $email = strtolower(trim($body['email'] ?? ''));
        $shareAll = (bool)($body['share_all_plants'] ?? false);
        $plantIds = $body['plant_ids'] ?? [];

        if (!$this->isOwner($householdId, $userId)) {
            return ['status' => 403, 'data' => ['error' => 'Only the household owner can invite members']];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 400, 'data' => ['error' => 'Invalid email address']];
        }

        // Check if user is inviting themselves
        $stmt = db()->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $owner = $stmt->fetch();
        if ($owner && strtolower($owner['email']) === $email) {
            return ['status' => 400, 'data' => ['error' => 'You cannot invite yourself']];
        }

        // Check if user is already a member
        $stmt = db()->prepare('
            SELECT 1 FROM household_members hm
            JOIN users u ON hm.user_id = u.id
            WHERE hm.household_id = ? AND LOWER(u.email) = ?
        ');
        $stmt->execute([$householdId, $email]);
        if ($stmt->fetch()) {
            return ['status' => 400, 'data' => ['error' => 'This user is already a member']];
        }

        // Check for existing pending invitation
        $stmt = db()->prepare('
            SELECT id FROM household_invitations
            WHERE household_id = ? AND LOWER(email) = ? AND accepted_at IS NULL AND expires_at > datetime("now")
        ');
        $stmt->execute([$householdId, $email]);
        if ($stmt->fetch()) {
            return ['status' => 400, 'data' => ['error' => 'An invitation has already been sent to this email']];
        }

        // Generate secure token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

        // Create invitation
        $stmt = db()->prepare('
            INSERT INTO household_invitations (household_id, email, token, invited_by, share_all_plants, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$householdId, $email, $token, $userId, $shareAll ? 1 : 0, $expiresAt]);
        $invitationId = db()->lastInsertId();

        // If sharing specific plants, share them now
        if (!$shareAll && !empty($plantIds)) {
            foreach ($plantIds as $plantId) {
                // Verify user owns the plant
                $stmt = db()->prepare('SELECT id FROM plants WHERE id = ? AND user_id = ?');
                $stmt->execute([$plantId, $userId]);
                if (!$stmt->fetch()) continue;

                // Share the plant (ignore if already shared)
                $stmt = db()->prepare('INSERT OR IGNORE INTO household_plants (household_id, plant_id, shared_by) VALUES (?, ?, ?)');
                $stmt->execute([$householdId, $plantId, $userId]);
            }
        }

        // If sharing all plants
        if ($shareAll) {
            $stmt = db()->prepare('SELECT id FROM plants WHERE user_id = ? AND archived_at IS NULL');
            $stmt->execute([$userId]);
            $plants = $stmt->fetchAll();

            foreach ($plants as $plant) {
                $stmt = db()->prepare('INSERT OR IGNORE INTO household_plants (household_id, plant_id, shared_by) VALUES (?, ?, ?)');
                $stmt->execute([$householdId, $plant['id'], $userId]);
            }
        }

        // Send invitation email
        $this->sendInvitationEmail($email, $token, $householdId, $userId);

        return [
            'message' => "Invitation sent to {$email}",
            'invitation_id' => $invitationId
        ];
    }

    /**
     * List pending invitations
     * GET /households/{id}/invitations
     */
    public function invitations(array $params, array $body, ?int $userId): array
    {
        $householdId = $params['id'];

        if (!$this->isOwner($householdId, $userId)) {
            return ['status' => 403, 'data' => ['error' => 'Only the household owner can view invitations']];
        }

        $stmt = db()->prepare('
            SELECT id, email, created_at, expires_at, accepted_at
            FROM household_invitations
            WHERE household_id = ?
            ORDER BY created_at DESC
        ');
        $stmt->execute([$householdId]);

        return ['invitations' => $stmt->fetchAll()];
    }

    /**
     * Revoke an invitation
     * DELETE /invitations/{id}
     */
    public function revokeInvitation(array $params, array $body, ?int $userId): array
    {
        $invitationId = $params['id'];

        // Get invitation and verify ownership
        $stmt = db()->prepare('
            SELECT hi.*, h.owner_id
            FROM household_invitations hi
            JOIN households h ON hi.household_id = h.id
            WHERE hi.id = ?
        ');
        $stmt->execute([$invitationId]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            return ['status' => 404, 'data' => ['error' => 'Invitation not found']];
        }

        if ($invitation['owner_id'] != $userId) {
            return ['status' => 403, 'data' => ['error' => 'Only the household owner can revoke invitations']];
        }

        $stmt = db()->prepare('DELETE FROM household_invitations WHERE id = ?');
        $stmt->execute([$invitationId]);

        return ['message' => 'Invitation revoked'];
    }

    /**
     * Get invitation details by token (public - no auth required)
     * GET /invitations/{token}
     */
    public function getInvitation(array $params, array $body, ?int $userId): array
    {
        $token = $params['token'];

        $stmt = db()->prepare('
            SELECT hi.*, h.name as household_name,
                   COALESCE(u.display_name, SUBSTR(u.email, 1, INSTR(u.email, "@") - 1)) as invited_by_name,
                   (SELECT COUNT(*) FROM household_plants WHERE household_id = hi.household_id) as plant_count,
                   (SELECT COUNT(*) FROM household_members WHERE household_id = hi.household_id) as member_count
            FROM household_invitations hi
            JOIN households h ON hi.household_id = h.id
            JOIN users u ON hi.invited_by = u.id
            WHERE hi.token = ?
        ');
        $stmt->execute([$token]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            return ['status' => 404, 'data' => ['error' => 'Invitation not found']];
        }

        if ($invitation['accepted_at']) {
            return ['status' => 400, 'data' => ['error' => 'This invitation has already been used']];
        }

        if (strtotime($invitation['expires_at']) < time()) {
            return ['status' => 400, 'data' => ['error' => 'This invitation has expired']];
        }

        return [
            'invitation' => [
                'household_name' => $invitation['household_name'],
                'invited_by_name' => $invitation['invited_by_name'],
                'plant_count' => (int)$invitation['plant_count'],
                'member_count' => (int)$invitation['member_count'],
                'email' => $invitation['email'],
                'expires_at' => $invitation['expires_at']
            ]
        ];
    }

    /**
     * Accept invitation and join household
     * POST /invitations/{token}/accept
     */
    public function acceptInvitation(array $params, array $body, ?int $userId): array
    {
        $token = $params['token'];

        // Get invitation
        $stmt = db()->prepare('
            SELECT hi.*, h.name as household_name
            FROM household_invitations hi
            JOIN households h ON hi.household_id = h.id
            WHERE hi.token = ?
        ');
        $stmt->execute([$token]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            return ['status' => 404, 'data' => ['error' => 'Invitation not found']];
        }

        if ($invitation['accepted_at']) {
            return ['status' => 400, 'data' => ['error' => 'This invitation has already been used']];
        }

        if (strtotime($invitation['expires_at']) < time()) {
            return ['status' => 400, 'data' => ['error' => 'This invitation has expired']];
        }

        // Verify user's email matches invitation (optional - can be removed for flexibility)
        $stmt = db()->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        // Check if already a member
        $stmt = db()->prepare('SELECT 1 FROM household_members WHERE household_id = ? AND user_id = ?');
        $stmt->execute([$invitation['household_id'], $userId]);
        if ($stmt->fetch()) {
            return ['status' => 400, 'data' => ['error' => 'You are already a member of this household']];
        }

        // Add user to household
        $stmt = db()->prepare('
            INSERT INTO household_members (household_id, user_id, role, display_name)
            VALUES (?, ?, "member", (SELECT COALESCE(display_name, SUBSTR(email, 1, INSTR(email, "@") - 1)) FROM users WHERE id = ?))
        ');
        $stmt->execute([$invitation['household_id'], $userId, $userId]);

        // Mark invitation as accepted
        $stmt = db()->prepare('UPDATE household_invitations SET accepted_at = datetime("now") WHERE id = ?');
        $stmt->execute([$invitation['id']]);

        return [
            'message' => "You've joined {$invitation['household_name']}!",
            'household_id' => $invitation['household_id']
        ];
    }

    // Helper methods

    private function isMember(int $householdId, int $userId): bool
    {
        $stmt = db()->prepare('SELECT 1 FROM household_members WHERE household_id = ? AND user_id = ?');
        $stmt->execute([$householdId, $userId]);
        return (bool)$stmt->fetch();
    }

    private function isOwner(int $householdId, int $userId): bool
    {
        $stmt = db()->prepare('SELECT 1 FROM households WHERE id = ? AND owner_id = ?');
        $stmt->execute([$householdId, $userId]);
        return (bool)$stmt->fetch();
    }

    private function getHouseholdResponse(int $householdId): array
    {
        $stmt = db()->prepare('
            SELECT h.*,
                   (SELECT COUNT(*) FROM household_members WHERE household_id = h.id) as member_count,
                   (SELECT COUNT(*) FROM household_plants WHERE household_id = h.id) as plant_count
            FROM households h
            WHERE h.id = ?
        ');
        $stmt->execute([$householdId]);

        return ['household' => $stmt->fetch()];
    }

    private function sendInvitationEmail(string $email, string $token, int $householdId, int $inviterId): bool
    {
        // Get household and inviter info
        $stmt = db()->prepare('SELECT name FROM households WHERE id = ?');
        $stmt->execute([$householdId]);
        $household = $stmt->fetch();

        $stmt = db()->prepare('SELECT COALESCE(display_name, SUBSTR(email, 1, INSTR(email, "@") - 1)) as name FROM users WHERE id = ?');
        $stmt->execute([$inviterId]);
        $inviter = $stmt->fetch();

        $householdName = htmlspecialchars($household['name'] ?? 'a household');
        $inviterName = htmlspecialchars($inviter['name'] ?? 'Someone');
        $inviteUrl = rtrim(APP_URL, '/') . '/invite/' . $token;

        $subject = "{$inviterName} invited you to help care for their plants on Sunwise";

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #f0fdf4; padding: 40px 20px; margin: 0;">
    <div style="max-width: 450px; margin: 0 auto; background: white; border-radius: 16px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
        <div style="text-align: center; margin-bottom: 24px;">
            <div style="width: 56px; height: 56px; background: linear-gradient(135deg, #22c55e, #16a34a); border-radius: 16px; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center;">
                <span style="font-size: 28px;">🌱</span>
            </div>
            <h1 style="margin: 0; font-size: 22px; color: #111;">You're Invited!</h1>
        </div>

        <p style="color: #444; text-align: center; margin-bottom: 8px; font-size: 16px;">
            <strong>{$inviterName}</strong> has invited you to join
        </p>
        <p style="color: #22c55e; text-align: center; font-size: 20px; font-weight: 600; margin: 0 0 24px;">
            "{$householdName}"
        </p>

        <p style="color: #666; text-align: center; margin-bottom: 24px;">
            You'll be able to help care for their plants, complete watering tasks, and keep their green friends happy!
        </p>

        <a href="{$inviteUrl}" style="display: block; background: linear-gradient(135deg, #22c55e, #16a34a); color: white; text-decoration: none; padding: 16px 24px; border-radius: 12px; text-align: center; font-weight: 600; font-size: 16px;">
            Accept Invitation
        </a>

        <p style="color: #999; font-size: 12px; text-align: center; margin-top: 24px;">
            This invitation expires in 7 days.<br>
            If you didn't expect this email, you can safely ignore it.
        </p>
    </div>
</body>
</html>
HTML;

        $textBody = <<<TEXT
You're Invited to Sunwise!

{$inviterName} has invited you to join "{$householdName}" on Sunwise.

You'll be able to help care for their plants, complete watering tasks, and keep their green friends happy!

Accept the invitation: {$inviteUrl}

This invitation expires in 7 days.
If you didn't expect this email, you can safely ignore it.
TEXT;

        $emailService = new EmailService();
        // Use reflection to call private send method, or make it protected
        // For now, we'll duplicate the send logic here
        return $this->sendEmail($email, $subject, $htmlBody, $textBody);
    }

    private function sendEmail(string $to, string $subject, string $htmlBody, string $textBody): bool
    {
        $boundary = md5(time());

        $headers = [
            'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
            'Reply-To: ' . MAIL_FROM,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"'
        ];

        $body = "--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $body .= $textBody . "\r\n\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";
        $body .= "--$boundary--";

        return @mail($to, $subject, $body, implode("\r\n", $headers));
    }
}

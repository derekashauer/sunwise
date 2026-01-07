<?php
/**
 * Plant Controller
 */

class PlantController
{
    /**
     * Get SQL condition for plants user can access (owned + shared via household)
     */
    private function getAccessiblePlantIds(int $userId): string
    {
        // Get IDs of households user belongs to
        $stmt = db()->prepare('SELECT household_id FROM household_members WHERE user_id = ?');
        $stmt->execute([$userId]);
        $households = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($households)) {
            // User not in any household - only their own plants
            return "p.user_id = {$userId}";
        }

        $householdList = implode(',', array_map('intval', $households));
        return "(p.user_id = {$userId} OR p.id IN (SELECT plant_id FROM household_plants WHERE household_id IN ({$householdList})))";
    }

    /**
     * Check if user can access a specific plant (owns it or has household access)
     */
    private function canAccessPlant(int $plantId, int $userId): bool
    {
        // Check ownership
        $stmt = db()->prepare('SELECT user_id FROM plants WHERE id = ?');
        $stmt->execute([$plantId]);
        $plant = $stmt->fetch();

        if (!$plant) return false;
        if ($plant['user_id'] == $userId) return true;

        // Check household access
        $stmt = db()->prepare('
            SELECT 1 FROM household_plants hp
            JOIN household_members hm ON hp.household_id = hm.household_id
            WHERE hp.plant_id = ? AND hm.user_id = ?
        ');
        $stmt->execute([$plantId, $userId]);
        return (bool)$stmt->fetch();
    }

    /**
     * List all active plants for user (excludes archived)
     * Includes both owned plants and plants shared via household
     */
    public function index(array $params, array $body, ?int $userId): array
    {
        $accessCondition = $this->getAccessiblePlantIds($userId);

        $stmt = db()->query("
            SELECT p.*,
                   l.name as location_name,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as thumbnail,
                   CASE WHEN p.user_id = {$userId} THEN 1 ELSE 0 END as is_owned,
                   CASE WHEN p.user_id != {$userId} THEN
                       (SELECT COALESCE(u.display_name, SUBSTR(u.email, 1, INSTR(u.email, '@') - 1))
                        FROM users u WHERE u.id = p.user_id)
                   ELSE NULL END as owner_name
            FROM plants p
            LEFT JOIN locations l ON p.location_id = l.id
            WHERE {$accessCondition} AND p.archived_at IS NULL
            ORDER BY p.created_at DESC
        ");
        $plants = $stmt->fetchAll();

        return ['plants' => $plants];
    }

    /**
     * List archived plants (graveyard)
     */
    public function archived(array $params, array $body, ?int $userId): array
    {
        $stmt = db()->prepare('
            SELECT p.*,
                   l.name as location_name,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as thumbnail
            FROM plants p
            LEFT JOIN locations l ON p.location_id = l.id
            WHERE p.user_id = ? AND p.archived_at IS NOT NULL
            ORDER BY p.archived_at DESC
        ');
        $stmt->execute([$userId]);
        $plants = $stmt->fetchAll();

        return ['plants' => $plants];
    }

    /**
     * Archive a plant (send to graveyard)
     */
    public function archive(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        // Verify ownership
        $stmt = db()->prepare('SELECT id, archived_at FROM plants WHERE id = ? AND user_id = ?');
        $stmt->execute([$plantId, $userId]);
        $plant = $stmt->fetch();

        if (!$plant) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        if ($plant['archived_at']) {
            return ['status' => 400, 'data' => ['error' => 'Plant is already archived']];
        }

        $deathReason = $body['death_reason'] ?? null;

        // Archive the plant
        $stmt = db()->prepare('
            UPDATE plants
            SET archived_at = datetime("now"), death_reason = ?
            WHERE id = ?
        ');
        $stmt->execute([$deathReason, $plantId]);

        // Deactivate care plan
        $stmt = db()->prepare('UPDATE care_plans SET is_active = 0 WHERE plant_id = ?');
        $stmt->execute([$plantId]);

        // Delete pending tasks
        $stmt = db()->prepare('
            DELETE FROM tasks
            WHERE plant_id = ? AND completed_at IS NULL AND skipped_at IS NULL
        ');
        $stmt->execute([$plantId]);

        // Remove any sitter access for this plant
        $stmt = db()->prepare('
            DELETE FROM sitter_plants WHERE plant_id = ?
        ');
        $stmt->execute([$plantId]);

        return ['message' => 'Plant archived', 'archived_at' => date('Y-m-d H:i:s')];
    }

    /**
     * Get public plant view (no auth required)
     * GET /plants/share/{id}
     */
    public function publicShare(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        $stmt = db()->prepare('
            SELECT p.id, p.name, p.species, p.created_at,
                   u.email as owner_email,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as photo
            FROM plants p
            JOIN users u ON p.user_id = u.id
            WHERE p.id = ? AND p.archived_at IS NULL
        ');
        $stmt->execute([$plantId]);
        $plant = $stmt->fetch();

        if (!$plant) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        // Get owner's first name from email (before @)
        $ownerName = explode('@', $plant['owner_email'])[0];
        unset($plant['owner_email']); // Don't expose full email

        return [
            'plant' => $plant,
            'owner_name' => ucfirst($ownerName)
        ];
    }

    /**
     * Create new plant
     */
    public function store(array $params, array $body, ?int $userId): array
    {
        $name = trim($body['name'] ?? '');

        if (!$name) {
            return ['status' => 400, 'data' => ['error' => 'Plant name is required']];
        }

        // Handle image upload
        $imageFilename = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $imageService = new ImageService();
            $imageFilename = $imageService->upload($_FILES['image']);

            if (!$imageFilename) {
                return ['status' => 400, 'data' => ['error' => 'Failed to upload image']];
            }
        }

        // Insert plant
        $stmt = db()->prepare('
            INSERT INTO plants (user_id, name, species, pot_size, soil_type, light_condition, location_id, notes, parent_plant_id, propagation_date, is_propagation, has_grow_light, grow_light_hours)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $userId,
            $name,
            $body['species'] ?? null,
            $body['pot_size'] ?? 'medium',
            $body['soil_type'] ?? 'standard',
            $body['light_condition'] ?? 'medium',
            $body['location_id'] ?? null,
            $body['notes'] ?? null,
            $body['parent_plant_id'] ?? null,
            $body['propagation_date'] ?? null,
            $body['is_propagation'] ?? 0,
            $body['has_grow_light'] ?? 0,
            $body['grow_light_hours'] ?? null
        ]);

        $plantId = db()->lastInsertId();

        // Save photo if uploaded
        if ($imageFilename) {
            $stmt = db()->prepare('INSERT INTO photos (plant_id, filename) VALUES (?, ?)');
            $stmt->execute([$plantId, $imageFilename]);
            $photoId = db()->lastInsertId();

            // Only trigger AI identification if species wasn't manually entered
            $speciesProvided = !empty(trim($body['species'] ?? ''));
            if ($speciesProvided) {
                // Species was manually entered - mark as confirmed and generate care plan
                $stmt = db()->prepare('UPDATE plants SET species_confirmed = 1 WHERE id = ?');
                $stmt->execute([$plantId]);

                // Generate care plan for the manually entered species
                try {
                    $carePlanController = new CarePlanController();
                    $carePlanController->generateCarePlan($plantId);
                } catch (Exception $e) {
                    error_log('Failed to generate care plan: ' . $e->getMessage());
                }
            } else {
                // No species entered - trigger AI identification
                $this->triggerAIIdentification($plantId, $photoId, $imageFilename);
            }
        }

        // Get the created plant
        $stmt = db()->prepare('SELECT * FROM plants WHERE id = ?');
        $stmt->execute([$plantId]);
        $plant = $stmt->fetch();
        $plant['thumbnail'] = $imageFilename;

        return ['plant' => $plant];
    }

    /**
     * Get single plant
     */
    public function show(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        // Check if user can access this plant (owned or shared via household)
        if (!$this->canAccessPlant($plantId, $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        $stmt = db()->prepare('
            SELECT p.*,
                   l.name as location_name,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as thumbnail,
                   CASE WHEN p.user_id = ? THEN 1 ELSE 0 END as is_owned,
                   CASE WHEN p.user_id != ? THEN
                       (SELECT COALESCE(u.display_name, SUBSTR(u.email, 1, INSTR(u.email, \'@\') - 1))
                        FROM users u WHERE u.id = p.user_id)
                   ELSE NULL END as owner_name
            FROM plants p
            LEFT JOIN locations l ON p.location_id = l.id
            WHERE p.id = ?
        ');
        $stmt->execute([$userId, $userId, $plantId]);
        $plant = $stmt->fetch();

        if (!$plant) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        // Add Wikipedia link for species
        if ($plant['species']) {
            $plant['wikipedia_url'] = 'https://en.wikipedia.org/wiki/' . urlencode(str_replace(' ', '_', $plant['species']));
        }

        return ['plant' => $plant];
    }

    /**
     * Update plant
     */
    public function update(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        // Verify access (owned or shared via household)
        if (!$this->canAccessPlant($plantId, $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        // Build update query dynamically
        $allowedFields = ['name', 'species', 'pot_size', 'soil_type', 'light_condition', 'location_id', 'notes', 'health_status', 'parent_plant_id', 'propagation_date', 'is_propagation', 'has_grow_light', 'grow_light_hours'];
        $updates = [];
        $values = [];

        foreach ($allowedFields as $field) {
            if (isset($body[$field])) {
                $updates[] = "$field = ?";
                $values[] = $body[$field];
            }
        }

        if (empty($updates)) {
            return ['status' => 400, 'data' => ['error' => 'No fields to update']];
        }

        $values[] = $plantId;
        $sql = 'UPDATE plants SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $stmt = db()->prepare($sql);
        $stmt->execute($values);

        // Return updated plant
        $stmt = db()->prepare('SELECT * FROM plants WHERE id = ?');
        $stmt->execute([$plantId]);

        return ['plant' => $stmt->fetch()];
    }

    /**
     * Delete plant
     */
    public function destroy(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        // Verify ownership
        $stmt = db()->prepare('SELECT id FROM plants WHERE id = ? AND user_id = ?');
        $stmt->execute([$plantId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        // Delete photos from filesystem
        $stmt = db()->prepare('SELECT filename, thumbnail FROM photos WHERE plant_id = ?');
        $stmt->execute([$plantId]);
        $photos = $stmt->fetchAll();

        $imageService = new ImageService();
        foreach ($photos as $photo) {
            $imageService->delete($photo['filename']);
            if ($photo['thumbnail']) {
                $imageService->delete($photo['thumbnail']);
            }
        }

        // Delete plant (cascade will handle related records)
        $stmt = db()->prepare('DELETE FROM plants WHERE id = ?');
        $stmt->execute([$plantId]);

        return ['message' => 'Plant deleted successfully'];
    }

    /**
     * Generate a fun plant name
     */
    public function generateName(array $params, array $body, ?int $userId): array
    {
        $count = min((int)($body['count'] ?? $_GET['count'] ?? 5), 10);
        $names = PlantNameGenerator::generateMultiple($count);

        return ['names' => $names];
    }

    /**
     * Confirm species selection from AI candidates
     */
    public function confirmSpecies(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];
        $species = $body['species'] ?? null;

        if (!$species) {
            return ['status' => 400, 'data' => ['error' => 'Species is required']];
        }

        // Verify ownership
        $stmt = db()->prepare('SELECT id FROM plants WHERE id = ? AND user_id = ?');
        $stmt->execute([$plantId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        // Update species and mark as confirmed
        $stmt = db()->prepare('
            UPDATE plants
            SET species = ?, species_confirmed = 1
            WHERE id = ?
        ');
        $stmt->execute([$species, $plantId]);

        // Regenerate care plan with confirmed species
        try {
            $carePlanController = new CarePlanController();
            $carePlanController->generateCarePlan($plantId);
        } catch (Exception $e) {
            error_log('Failed to regenerate care plan: ' . $e->getMessage());
        }

        return ['message' => 'Species confirmed', 'species' => $species];
    }

    /**
     * Trigger AI identification (async in future, sync for now)
     */
    private function triggerAIIdentification(int $plantId, int $photoId, string $filename): void
    {
        try {
            $claudeService = new ClaudeService();
            $imagePath = UPLOAD_PATH . '/plants/' . $filename;

            $result = $claudeService->identifyPlant($imagePath);

            if ($result) {
                // Store species candidates if multiple possibilities
                $candidates = $result['candidates'] ?? null;
                $candidatesJson = $candidates ? json_encode($candidates) : null;

                // Update plant with identified species
                $stmt = db()->prepare('
                    UPDATE plants
                    SET species = ?, species_confidence = ?, health_status = ?,
                        last_health_check = datetime("now"), species_candidates = ?,
                        species_confirmed = 0
                    WHERE id = ?
                ');
                $stmt->execute([
                    $result['species'] ?? null,
                    $result['confidence'] ?? null,
                    $result['health_status'] ?? 'unknown',
                    $candidatesJson,
                    $plantId
                ]);

                // Store AI analysis in photo
                $stmt = db()->prepare('UPDATE photos SET ai_analysis = ? WHERE id = ?');
                $stmt->execute([json_encode($result), $photoId]);

                // Generate initial care plan
                $carePlanController = new CarePlanController();
                $carePlanController->generateCarePlan($plantId);
            }
        } catch (Exception $e) {
            error_log('AI identification failed: ' . $e->getMessage());
        }
    }
}

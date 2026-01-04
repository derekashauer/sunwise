<?php
/**
 * Shopping List Controller
 */

class ShoppingListController
{
    /**
     * Get all shopping list items for user
     */
    public function index(array $params, array $body, ?int $userId): array
    {
        $showPurchased = isset($_GET['purchased']) && $_GET['purchased'] === '1';

        $sql = '
            SELECT s.*, p.name as plant_name, p.species as plant_species
            FROM shopping_list s
            LEFT JOIN plants p ON s.plant_id = p.id
            WHERE s.user_id = ?
        ';

        if (!$showPurchased) {
            $sql .= ' AND s.purchased = 0';
        }

        $sql .= ' ORDER BY s.purchased ASC, s.created_at DESC';

        $stmt = db()->prepare($sql);
        $stmt->execute([$userId]);

        return ['items' => $stmt->fetchAll()];
    }

    /**
     * Add item to shopping list
     */
    public function store(array $params, array $body, ?int $userId): array
    {
        $item = trim($body['item'] ?? '');

        if (!$item) {
            return ['status' => 400, 'data' => ['error' => 'Item name is required']];
        }

        $plantId = $body['plant_id'] ?? null;

        // Verify plant ownership if plant_id provided
        if ($plantId) {
            $stmt = db()->prepare('SELECT id FROM plants WHERE id = ? AND user_id = ?');
            $stmt->execute([$plantId, $userId]);
            if (!$stmt->fetch()) {
                return ['status' => 400, 'data' => ['error' => 'Invalid plant']];
            }
        }

        $stmt = db()->prepare('
            INSERT INTO shopping_list (user_id, plant_id, item, quantity, notes)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $userId,
            $plantId,
            $item,
            $body['quantity'] ?? 1,
            $body['notes'] ?? null
        ]);

        $id = db()->lastInsertId();

        // Get created item with plant info
        $stmt = db()->prepare('
            SELECT s.*, p.name as plant_name, p.species as plant_species
            FROM shopping_list s
            LEFT JOIN plants p ON s.plant_id = p.id
            WHERE s.id = ?
        ');
        $stmt->execute([$id]);

        return ['item' => $stmt->fetch()];
    }

    /**
     * Update shopping list item
     */
    public function update(array $params, array $body, ?int $userId): array
    {
        $itemId = $params['id'];

        // Verify ownership
        $stmt = db()->prepare('SELECT id FROM shopping_list WHERE id = ? AND user_id = ?');
        $stmt->execute([$itemId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Item not found']];
        }

        $allowedFields = ['item', 'quantity', 'notes', 'plant_id'];
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

        $values[] = $itemId;
        $sql = 'UPDATE shopping_list SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $stmt = db()->prepare($sql);
        $stmt->execute($values);

        // Get updated item
        $stmt = db()->prepare('
            SELECT s.*, p.name as plant_name, p.species as plant_species
            FROM shopping_list s
            LEFT JOIN plants p ON s.plant_id = p.id
            WHERE s.id = ?
        ');
        $stmt->execute([$itemId]);

        return ['item' => $stmt->fetch()];
    }

    /**
     * Toggle purchased status
     */
    public function togglePurchased(array $params, array $body, ?int $userId): array
    {
        $itemId = $params['id'];

        // Verify ownership and get current status
        $stmt = db()->prepare('SELECT id, purchased FROM shopping_list WHERE id = ? AND user_id = ?');
        $stmt->execute([$itemId, $userId]);
        $item = $stmt->fetch();

        if (!$item) {
            return ['status' => 404, 'data' => ['error' => 'Item not found']];
        }

        $newStatus = $item['purchased'] ? 0 : 1;
        $purchasedAt = $newStatus ? date('Y-m-d H:i:s') : null;

        $stmt = db()->prepare('UPDATE shopping_list SET purchased = ?, purchased_at = ? WHERE id = ?');
        $stmt->execute([$newStatus, $purchasedAt, $itemId]);

        // Get updated item
        $stmt = db()->prepare('
            SELECT s.*, p.name as plant_name, p.species as plant_species
            FROM shopping_list s
            LEFT JOIN plants p ON s.plant_id = p.id
            WHERE s.id = ?
        ');
        $stmt->execute([$itemId]);

        return ['item' => $stmt->fetch()];
    }

    /**
     * Delete shopping list item
     */
    public function destroy(array $params, array $body, ?int $userId): array
    {
        $itemId = $params['id'];

        // Verify ownership
        $stmt = db()->prepare('SELECT id FROM shopping_list WHERE id = ? AND user_id = ?');
        $stmt->execute([$itemId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Item not found']];
        }

        $stmt = db()->prepare('DELETE FROM shopping_list WHERE id = ?');
        $stmt->execute([$itemId]);

        return ['message' => 'Item deleted'];
    }

    /**
     * Clear all purchased items
     */
    public function clearPurchased(array $params, array $body, ?int $userId): array
    {
        $stmt = db()->prepare('DELETE FROM shopping_list WHERE user_id = ? AND purchased = 1');
        $stmt->execute([$userId]);

        return ['message' => 'Purchased items cleared', 'deleted' => $stmt->rowCount()];
    }
}

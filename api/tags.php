<?php
require_once 'db.php';
require_once '../includes/functions.php';

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];
$request = $_SERVER['REQUEST_URI'];
$path = parse_url($request, PHP_URL_PATH);
$pathSegments = explode('/', $path);
$id = isset($pathSegments[4]) ? (int)$pathSegments[4] : null;

try {
    $pdo = getDbConnection();
    
    switch ($method) {
        case 'GET':
            if ($id) {
                getTagById($pdo, $id);
            } else {
                getAllTags($pdo);
            }
            break;
            
        case 'POST':
            createTag($pdo);
            break;
            
        case 'PUT':
            if ($id) {
                updateTag($pdo, $id);
            } else {
                errorResponse('Tag ID required for update', 400);
            }
            break;
            
        case 'DELETE':
            if ($id) {
                deleteTag($pdo, $id);
            } else {
                errorResponse('Tag ID required for delete', 400);
            }
            break;
            
        default:
            errorResponse('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    logError('Tags API error: ' . $e->getMessage());
    errorResponse('Server error: ' . $e->getMessage(), 500);
}

// GET all tags with usage counts
function getAllTags($pdo) {
    try {
        // Get query parameters
        $includeUsage = isset($_GET['usage']) && $_GET['usage'] === 'true';
        $filterUsed = isset($_GET['used_only']) && $_GET['used_only'] === 'true';
        $search = $_GET['search'] ?? '';
        
        $baseQuery = "SELECT t.id, t.name";
        $fromClause = " FROM tags t";
        $whereConditions = [];
        $params = [];
        
        if ($includeUsage) {
            $baseQuery .= ", COUNT(st.section_id) as usage_count";
            $fromClause .= " LEFT JOIN section_tags st ON t.id = st.tag_id";
        }
        
        if ($filterUsed) {
            $whereConditions[] = "EXISTS (SELECT 1 FROM section_tags WHERE tag_id = t.id)";
        }
        
        if (!empty($search)) {
            $whereConditions[] = "t.name LIKE ?";
            $params[] = "%{$search}%";
        }
        
        $whereClause = !empty($whereConditions) ? " WHERE " . implode(" AND ", $whereConditions) : "";
        $groupClause = $includeUsage ? " GROUP BY t.id, t.name" : "";
        $orderClause = $includeUsage ? " ORDER BY usage_count DESC, t.name ASC" : " ORDER BY t.name ASC";
        
        $query = $baseQuery . $fromClause . $whereClause . $groupClause . $orderClause;
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $tags = $stmt->fetchAll();
        
        // Convert usage_count to integer
        if ($includeUsage) {
            foreach ($tags as &$tag) {
                $tag['usage_count'] = (int)$tag['usage_count'];
            }
        }
        
        successResponse($tags, 'Tags retrieved successfully');
        
    } catch (PDOException $e) {
        errorResponse('Database error: ' . $e->getMessage(), 500);
    }
}

// GET single tag with sections
function getTagById($pdo, $id) {
    try {
        $stmt = $pdo->prepare(
            "SELECT t.id, t.name, COUNT(st.section_id) as usage_count
             FROM tags t
             LEFT JOIN section_tags st ON t.id = st.tag_id
             WHERE t.id = ?
             GROUP BY t.id, t.name"
        );
        $stmt->execute([$id]);
        $tag = $stmt->fetch();
        
        if (!$tag) {
            errorResponse('Tag not found', 404);
        }
        
        $tag['usage_count'] = (int)$tag['usage_count'];
        
        // Get sections using this tag
        $stmt = $pdo->prepare(
            "SELECT s.id, s.internal_name, s.position, s.media_type, s.thumbnail_url
             FROM sections s
             INNER JOIN section_tags st ON s.id = st.section_id
             WHERE st.tag_id = ?
             ORDER BY s.position ASC"
        );
        $stmt->execute([$id]);
        $sections = $stmt->fetchAll();
        
        $tag['sections'] = $sections;
        
        successResponse($tag, 'Tag retrieved successfully');
        
    } catch (PDOException $e) {
        errorResponse('Database error: ' . $e->getMessage(), 500);
    }
}

// POST - Create new tag
function createTag($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['name']) || empty(trim($input['name']))) {
        errorResponse('Tag name is required', 400);
    }
    
    $tagName = sanitizeInput(trim($input['name']));
    
    // Validate tag name
    if (strlen($tagName) > 100) {
        errorResponse('Tag name too long (max 100 characters)', 400);
    }
    
    if (!preg_match('/^[a-zA-Z0-9\-_\s]+$/', $tagName)) {
        errorResponse('Tag name contains invalid characters', 400);
    }
    
    try {
        // Check if tag already exists
        $stmt = $pdo->prepare("SELECT id FROM tags WHERE name = ?");
        $stmt->execute([$tagName]);
        
        if ($stmt->fetch()) {
            errorResponse('Tag already exists', 409);
        }
        
        // Create new tag
        $stmt = $pdo->prepare("INSERT INTO tags (name) VALUES (?)");
        $stmt->execute([$tagName]);
        
        $tagId = $pdo->lastInsertId();
        
        successResponse(['id' => $tagId, 'name' => $tagName], 'Tag created successfully');
        
    } catch (PDOException $e) {
        errorResponse('Database error: ' . $e->getMessage(), 500);
    }
}

// PUT - Update tag
function updateTag($pdo, $id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['name']) || empty(trim($input['name']))) {
        errorResponse('Tag name is required', 400);
    }
    
    $tagName = sanitizeInput(trim($input['name']));
    
    // Validate tag name
    if (strlen($tagName) > 100) {
        errorResponse('Tag name too long (max 100 characters)', 400);
    }
    
    if (!preg_match('/^[a-zA-Z0-9\-_\s]+$/', $tagName)) {
        errorResponse('Tag name contains invalid characters', 400);
    }
    
    try {
        // Check if tag exists
        $stmt = $pdo->prepare("SELECT name FROM tags WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        
        if (!$existing) {
            errorResponse('Tag not found', 404);
        }
        
        // Check if new name conflicts with another tag
        $stmt = $pdo->prepare("SELECT id FROM tags WHERE name = ? AND id != ?");
        $stmt->execute([$tagName, $id]);
        
        if ($stmt->fetch()) {
            errorResponse('Tag name already exists', 409);
        }
        
        // Update tag
        $stmt = $pdo->prepare("UPDATE tags SET name = ? WHERE id = ?");
        $stmt->execute([$tagName, $id]);
        
        successResponse(['id' => $id, 'name' => $tagName], 'Tag updated successfully');
        
    } catch (PDOException $e) {
        errorResponse('Database error: ' . $e->getMessage(), 500);
    }
}

// DELETE tag
function deleteTag($pdo, $id) {
    try {
        // Check if tag exists and get usage count
        $stmt = $pdo->prepare(
            "SELECT t.name, COUNT(st.section_id) as usage_count
             FROM tags t
             LEFT JOIN section_tags st ON t.id = st.tag_id
             WHERE t.id = ?
             GROUP BY t.id, t.name"
        );
        $stmt->execute([$id]);
        $tag = $stmt->fetch();
        
        if (!$tag) {
            errorResponse('Tag not found', 404);
        }
        
        $usageCount = (int)$tag['usage_count'];
        
        // Check if forced deletion is requested
        $force = isset($_GET['force']) && $_GET['force'] === 'true';
        
        if ($usageCount > 0 && !$force) {
            errorResponse(
                "Tag is used by {$usageCount} section(s). Use ?force=true to delete anyway.",
                409
            );
        }
        
        // Delete tag (CASCADE will handle section_tags)
        $stmt = $pdo->prepare("DELETE FROM tags WHERE id = ?");
        $stmt->execute([$id]);
        
        successResponse([
            'id' => $id,
            'name' => $tag['name'],
            'sections_affected' => $usageCount
        ], 'Tag deleted successfully');
        
    } catch (PDOException $e) {
        errorResponse('Database error: ' . $e->getMessage(), 500);
    }
}

// Bulk operations
if ($method === 'POST' && isset($_GET['action'])) {
    $action = $_GET['action'];
    
    switch ($action) {
        case 'bulk_create':
            handleBulkCreate($pdo);
            break;
        case 'bulk_delete':
            handleBulkDelete($pdo);
            break;
        case 'cleanup_unused':
            handleCleanupUnused($pdo);
            break;
        default:
            errorResponse('Invalid action', 400);
    }
}

// Create multiple tags from comma-separated string
function handleBulkCreate($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['tags']) || empty($input['tags'])) {
        errorResponse('Tags string required', 400);
    }
    
    $tagsString = $input['tags'];
    $tagNames = array_map('trim', explode(',', $tagsString));
    $tagNames = array_filter($tagNames); // Remove empty strings
    
    if (empty($tagNames)) {
        errorResponse('No valid tags found', 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        $created = [];
        $skipped = [];
        
        foreach ($tagNames as $tagName) {
            $tagName = sanitizeInput($tagName);
            
            // Skip invalid names
            if (strlen($tagName) > 100 || !preg_match('/^[a-zA-Z0-9\-_\s]+$/', $tagName)) {
                $skipped[] = $tagName . ' (invalid format)';
                continue;
            }
            
            // Check if exists
            $stmt = $pdo->prepare("SELECT id FROM tags WHERE name = ?");
            $stmt->execute([$tagName]);
            
            if ($stmt->fetch()) {
                $skipped[] = $tagName . ' (already exists)';
                continue;
            }
            
            // Create tag
            $stmt = $pdo->prepare("INSERT INTO tags (name) VALUES (?)");
            $stmt->execute([$tagName]);
            
            $created[] = [
                'id' => $pdo->lastInsertId(),
                'name' => $tagName
            ];
        }
        
        $pdo->commit();
        
        successResponse([
            'created' => $created,
            'skipped' => $skipped,
            'created_count' => count($created),
            'skipped_count' => count($skipped)
        ], 'Bulk tag creation completed');
        
    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
}

// Delete multiple tags
function handleBulkDelete($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['tag_ids']) || !is_array($input['tag_ids'])) {
        errorResponse('Tag IDs array required', 400);
    }
    
    $tagIds = array_map('intval', $input['tag_ids']);
    $tagIds = array_filter($tagIds); // Remove zeros
    
    if (empty($tagIds)) {
        errorResponse('No valid tag IDs provided', 400);
    }
    
    try {
        $placeholders = str_repeat('?,', count($tagIds) - 1) . '?';
        
        $stmt = $pdo->prepare("DELETE FROM tags WHERE id IN ($placeholders)");
        $stmt->execute($tagIds);
        
        $deletedCount = $stmt->rowCount();
        
        successResponse([
            'deleted_count' => $deletedCount,
            'requested_count' => count($tagIds)
        ], 'Bulk tag deletion completed');
        
    } catch (PDOException $e) {
        errorResponse('Database error: ' . $e->getMessage(), 500);
    }
}

// Clean up unused tags
function handleCleanupUnused($pdo) {
    try {
        $stmt = $pdo->prepare(
            "DELETE FROM tags 
             WHERE id NOT IN (SELECT DISTINCT tag_id FROM section_tags)"
        );
        $stmt->execute();
        
        $deletedCount = $stmt->rowCount();
        
        successResponse([
            'deleted_count' => $deletedCount
        ], 'Unused tags cleaned up successfully');
        
    } catch (PDOException $e) {
        errorResponse('Database error: ' . $e->getMessage(), 500);
    }
}
?>
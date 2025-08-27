<?php
require_once 'db.php';

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Get HTTP method and ID from URL
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
                getSectionById($pdo, $id);
            } else {
                getAllSections($pdo);
            }
            break;
            
        case 'POST':
            createSection($pdo);
            break;
            
        case 'PUT':
            if ($id) {
                updateSection($pdo, $id);
            } else {
                errorResponse('Section ID required for update', 400);
            }
            break;
            
        case 'DELETE':
            if ($id) {
                deleteSection($pdo, $id);
            } else {
                errorResponse('Section ID required for delete', 400);
            }
            break;
            
        default:
            errorResponse('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}

// GET all sections with tags
function getAllSections($pdo) {
    try {
        $query = "SELECT s.*, GROUP_CONCAT(t.name) as tags 
                  FROM sections s
                  LEFT JOIN section_tags st ON s.id = st.section_id
                  LEFT JOIN tags t ON st.tag_id = t.id
                  GROUP BY s.id
                  ORDER BY s.position ASC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $sections = $stmt->fetchAll();
        
        // Convert tags string to array
        foreach ($sections as &$section) {
            $section['tags'] = $section['tags'] ? explode(',', $section['tags']) : [];
            $section['has_title'] = (bool)$section['has_title'];
        }
        
        successResponse($sections, 'Sections retrieved successfully');
        
    } catch (PDOException $e) {
        errorResponse('Database error: ' . $e->getMessage(), 500);
    }
}

// GET single section by ID
function getSectionById($pdo, $id) {
    try {
        $query = "SELECT s.*, GROUP_CONCAT(t.name) as tags 
                  FROM sections s
                  LEFT JOIN section_tags st ON s.id = st.section_id
                  LEFT JOIN tags t ON st.tag_id = t.id
                  WHERE s.id = ?
                  GROUP BY s.id";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);
        $section = $stmt->fetch();
        
        if (!$section) {
            errorResponse('Section not found', 404);
        }
        
        $section['tags'] = $section['tags'] ? explode(',', $section['tags']) : [];
        $section['has_title'] = (bool)$section['has_title'];
        
        successResponse($section, 'Section retrieved successfully');
        
    } catch (PDOException $e) {
        errorResponse('Database error: ' . $e->getMessage(), 500);
    }
}

// POST - Create new section
function createSection($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $requiredFields = ['internal_name', 'media_type', 'media_url'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            errorResponse("Field '$field' is required", 400);
        }
    }
    
    // Validate media type
    $validMediaTypes = ['image', 'video', 'youtube'];
    if (!in_array($input['media_type'], $validMediaTypes)) {
        errorResponse('Invalid media type', 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get next position
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 as next_position FROM sections");
        $stmt->execute();
        $nextPosition = $stmt->fetch()['next_position'];
        
        // Insert section
        $query = "INSERT INTO sections 
                  (internal_name, position, media_type, media_url, thumbnail_url, has_title, title, title_color, banner_color) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $input['internal_name'],
            $nextPosition,
            $input['media_type'],
            $input['media_url'],
            $input['thumbnail_url'] ?? null,
            isset($input['has_title']) ? (int)(bool)$input['has_title'] : 0,
            $input['title'] ?? null,
            $input['title_color'] ?? '#FFFFFF',
            $input['banner_color'] ?? 'rgba(0,0,0,0.5)'
        ]);
        
        $sectionId = $pdo->lastInsertId();
        
        // Handle tags if provided
        if (isset($input['tags']) && is_array($input['tags'])) {
            insertSectionTags($pdo, $sectionId, $input['tags']);
        }
        
        $pdo->commit();
        
        successResponse(['id' => $sectionId], 'Section created successfully');
        
    } catch (PDOException $e) {
        $pdo->rollback();
        errorResponse('Database error: ' . $e->getMessage(), 500);
    }
}

// PUT - Update section
function updateSection($pdo, $id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    try {
        $pdo->beginTransaction();
        
        // Build dynamic update query
        $fields = [];
        $values = [];
        
        $allowedFields = ['internal_name', 'media_type', 'media_url', 'thumbnail_url', 'has_title', 'title', 'title_color', 'banner_color'];
        
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $fields[] = "$field = ?";
                $values[] = $field === 'has_title' ? (int)(bool)$input[$field] : $input[$field];
            }
        }
        
        if (empty($fields)) {
            errorResponse('No valid fields to update', 400);
        }
        
        $values[] = $id;
        $query = "UPDATE sections SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($values);
        
        if ($stmt->rowCount() === 0) {
            errorResponse('Section not found', 404);
        }
        
        // Update tags if provided
        if (isset($input['tags']) && is_array($input['tags'])) {
            // Delete existing tags
            $stmt = $pdo->prepare("DELETE FROM section_tags WHERE section_id = ?");
            $stmt->execute([$id]);
            
            // Insert new tags
            insertSectionTags($pdo, $id, $input['tags']);
        }
        
        $pdo->commit();
        
        successResponse(['id' => $id], 'Section updated successfully');
        
    } catch (PDOException $e) {
        $pdo->rollback();
        errorResponse('Database error: ' . $e->getMessage(), 500);
    }
}

// DELETE section
function deleteSection($pdo, $id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM sections WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() === 0) {
            errorResponse('Section not found', 404);
        }
        
        successResponse(['id' => $id], 'Section deleted successfully');
        
    } catch (PDOException $e) {
        errorResponse('Database error: ' . $e->getMessage(), 500);
    }
}

// Helper function to insert section tags
function insertSectionTags($pdo, $sectionId, $tags) {
    foreach ($tags as $tagName) {
        $tagName = trim($tagName);
        if (empty($tagName)) continue;
        
        // Insert or get tag ID
        $stmt = $pdo->prepare("INSERT IGNORE INTO tags (name) VALUES (?)");
        $stmt->execute([$tagName]);
        
        $stmt = $pdo->prepare("SELECT id FROM tags WHERE name = ?");
        $stmt->execute([$tagName]);
        $tagId = $stmt->fetch()['id'];
        
        // Link section to tag
        $stmt = $pdo->prepare("INSERT IGNORE INTO section_tags (section_id, tag_id) VALUES (?, ?)");
        $stmt->execute([$sectionId, $tagId]);
    }
}
?>
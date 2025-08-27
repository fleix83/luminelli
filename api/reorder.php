<?php
require_once 'db.php';
require_once '../includes/functions.php';

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only allow POST and PUT requests
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'])) {
    errorResponse('Method not allowed', 405);
}

try {
    // Rate limiting
    if (isRateLimited('reorder', 30, 3600)) { // 30 reorders per hour
        errorResponse('Rate limit exceeded. Please try again later.', 429);
    }
    
    $pdo = getDbConnection();
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        errorResponse('Invalid JSON input', 400);
    }
    
    // Handle different reorder operations
    $action = $input['action'] ?? 'batch_reorder';
    
    switch ($action) {
        case 'batch_reorder':
            handleBatchReorder($pdo, $input);
            break;
        case 'move_position':
            handleMovePosition($pdo, $input);
            break;
        case 'swap_positions':
            handleSwapPositions($pdo, $input);
            break;
        default:
            errorResponse('Invalid action', 400);
    }
    
} catch (Exception $e) {
    logError('Reorder error: ' . $e->getMessage());
    errorResponse('Reorder failed: ' . $e->getMessage(), 500);
}

// Handle batch reordering (most common for drag & drop)
function handleBatchReorder($pdo, $input) {
    if (!isset($input['sections']) || !is_array($input['sections'])) {
        errorResponse('Sections array required', 400);
    }
    
    $sections = $input['sections'];
    
    if (empty($sections)) {
        errorResponse('Empty sections array', 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Validate that all sections exist
        $sectionIds = array_column($sections, 'id');
        $placeholders = str_repeat('?,', count($sectionIds) - 1) . '?';
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM sections WHERE id IN ($placeholders)");
        $stmt->execute($sectionIds);
        $existingCount = $stmt->fetch()['count'];
        
        if ($existingCount !== count($sectionIds)) {
            throw new Exception('Some sections do not exist');
        }
        
        // Update positions
        $stmt = $pdo->prepare("UPDATE sections SET position = ? WHERE id = ?");
        
        foreach ($sections as $index => $section) {
            if (!isset($section['id'])) {
                throw new Exception('Section ID missing');
            }
            
            $newPosition = $index + 1; // 1-based positions
            $sectionId = (int)$section['id'];
            
            $stmt->execute([$newPosition, $sectionId]);
        }
        
        $pdo->commit();
        
        // Return updated order
        $updatedSections = getSectionsOrder($pdo);
        
        successResponse([
            'sections' => $updatedSections,
            'updated_count' => count($sections)
        ], 'Sections reordered successfully');
        
    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
}

// Handle moving a single section to a specific position
function handleMovePosition($pdo, $input) {
    if (!isset($input['section_id']) || !isset($input['new_position'])) {
        errorResponse('Section ID and new position required', 400);
    }
    
    $sectionId = (int)$input['section_id'];
    $newPosition = (int)$input['new_position'];
    
    if ($sectionId <= 0 || $newPosition <= 0) {
        errorResponse('Invalid section ID or position', 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get current position
        $stmt = $pdo->prepare("SELECT position FROM sections WHERE id = ?");
        $stmt->execute([$sectionId]);
        $section = $stmt->fetch();
        
        if (!$section) {
            throw new Exception('Section not found');
        }
        
        $currentPosition = (int)$section['position'];
        
        if ($currentPosition === $newPosition) {
            $pdo->rollback();
            successResponse([], 'Section already in target position');
            return;
        }
        
        // Shift other sections
        if ($newPosition < $currentPosition) {
            // Moving up - shift sections down
            $stmt = $pdo->prepare(
                "UPDATE sections 
                 SET position = position + 1 
                 WHERE position >= ? AND position < ? AND id != ?"
            );
            $stmt->execute([$newPosition, $currentPosition, $sectionId]);
        } else {
            // Moving down - shift sections up
            $stmt = $pdo->prepare(
                "UPDATE sections 
                 SET position = position - 1 
                 WHERE position > ? AND position <= ? AND id != ?"
            );
            $stmt->execute([$currentPosition, $newPosition, $sectionId]);
        }
        
        // Update target section position
        $stmt = $pdo->prepare("UPDATE sections SET position = ? WHERE id = ?");
        $stmt->execute([$newPosition, $sectionId]);
        
        $pdo->commit();
        
        // Return updated order
        $updatedSections = getSectionsOrder($pdo);
        
        successResponse([
            'sections' => $updatedSections,
            'moved_section' => $sectionId,
            'from_position' => $currentPosition,
            'to_position' => $newPosition
        ], 'Section moved successfully');
        
    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
}

// Handle swapping positions of two sections
function handleSwapPositions($pdo, $input) {
    if (!isset($input['section1_id']) || !isset($input['section2_id'])) {
        errorResponse('Both section IDs required', 400);
    }
    
    $section1Id = (int)$input['section1_id'];
    $section2Id = (int)$input['section2_id'];
    
    if ($section1Id <= 0 || $section2Id <= 0 || $section1Id === $section2Id) {
        errorResponse('Invalid section IDs', 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get both sections
        $stmt = $pdo->prepare("SELECT id, position FROM sections WHERE id IN (?, ?)");
        $stmt->execute([$section1Id, $section2Id]);
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($sections) !== 2) {
            throw new Exception('One or both sections not found');
        }
        
        // Map positions
        $positions = [];
        foreach ($sections as $section) {
            $positions[$section['id']] = $section['position'];
        }
        
        // Swap positions using temporary negative values to avoid conflicts
        $stmt = $pdo->prepare("UPDATE sections SET position = ? WHERE id = ?");
        
        // First, set to negative values
        $stmt->execute([-$positions[$section2Id], $section1Id]);
        $stmt->execute([-$positions[$section1Id], $section2Id]);
        
        // Then, set to positive values
        $stmt->execute([$positions[$section2Id], $section1Id]);
        $stmt->execute([$positions[$section1Id], $section2Id]);
        
        $pdo->commit();
        
        // Return updated order
        $updatedSections = getSectionsOrder($pdo);
        
        successResponse([
            'sections' => $updatedSections,
            'swapped' => [
                'section1' => ['id' => $section1Id, 'new_position' => $positions[$section2Id]],
                'section2' => ['id' => $section2Id, 'new_position' => $positions[$section1Id]]
            ]
        ], 'Sections swapped successfully');
        
    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
}

// Helper function to get sections in order
function getSectionsOrder($pdo) {
    $stmt = $pdo->prepare(
        "SELECT id, internal_name, position, media_type, thumbnail_url 
         FROM sections 
         ORDER BY position ASC"
    );
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Utility function to normalize positions (fix gaps)
function normalizePositions($pdo) {
    try {
        $pdo->beginTransaction();
        
        // Get all sections ordered by position
        $stmt = $pdo->prepare("SELECT id FROM sections ORDER BY position ASC");
        $stmt->execute();
        $sections = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Update positions to be sequential
        $updateStmt = $pdo->prepare("UPDATE sections SET position = ? WHERE id = ?");
        
        foreach ($sections as $index => $sectionId) {
            $newPosition = $index + 1;
            $updateStmt->execute([$newPosition, $sectionId]);
        }
        
        $pdo->commit();
        
        return count($sections);
        
    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
}

// Handle position normalization endpoint
if (isset($_GET['action']) && $_GET['action'] === 'normalize') {
    try {
        $pdo = getDbConnection();
        $count = normalizePositions($pdo);
        
        successResponse([
            'normalized_count' => $count
        ], 'Positions normalized successfully');
        
    } catch (Exception $e) {
        errorResponse('Normalization failed: ' . $e->getMessage(), 500);
    }
}
?>
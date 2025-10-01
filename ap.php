<?php
session_start();

// Define main owner credentials
define('MAIN_OWNER_USERNAME', 'kalua');
define('MAIN_OWNER_PASSWORD', 'kkk'); 

// Define file paths
define('DATABASE_FILE', 'apps.txt');
define('USERS_FILE', 'users.txt');
define('PENDING_LINKS_FILE', 'pending_links.txt');

// Set a simple response header
header('Content-Type: application/json');

// Get the POST data from the request body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Determine action: check GET (query), then POST (FormData), then JSON body
$action = $_GET['action'] ?? $_POST['action'] ?? ($data['action'] ?? '');

// Helper function to check if the user is a main owner
function is_main_owner() {
    return isset($_SESSION['is_main_owner']) && $_SESSION['is_main_owner'];
}

// Helper function to check if the user is a registered admin
function is_registered_admin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
}

switch ($action) {
    case 'login':
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        if ($username === MAIN_OWNER_USERNAME && $password === MAIN_OWNER_PASSWORD) {
            $_SESSION['is_main_owner'] = true;
            $_SESSION['is_admin'] = true;
            echo json_encode(['success' => true, 'message' => 'Main owner login successful.', 'is_main_owner' => true]);
        } else {
            $users = file_exists(USERS_FILE) ? file(USERS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
            $found_user = false;
            foreach ($users as $user_data) {
                list($user, $hashed_password) = explode(',', $user_data);
                if ($user === $username && password_verify($password, $hashed_password)) {
                    $_SESSION['is_admin'] = true;
                    $_SESSION['is_main_owner'] = false;
                    $found_user = true;
                    break;
                }
            }

            if ($found_user) {
                echo json_encode(['success' => true, 'message' => 'Admin login successful.', 'is_main_owner' => false]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
            }
        }
        break;

    case 'register':
        if (!is_main_owner()) {
            echo json_encode(['success' => false, 'message' => 'Only the main owner can register new users.']);
            exit;
        }

        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($username) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
            exit;
        }

        $users = file_exists(USERS_FILE) ? file(USERS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        foreach ($users as $user_data) {
            list($user, ) = explode(',', $user_data);
            if ($user === $username) {
                echo json_encode(['success' => false, 'message' => 'Username already exists.']);
                exit;
            }
        }

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $user_entry = $username . ',' . $hashed_password . "\n";

        if (file_put_contents(USERS_FILE, $user_entry, FILE_APPEND | LOCK_EX)) {
            echo json_encode(['success' => true, 'message' => 'User registered successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to register user. Check file permissions.']);
        }
        break;
        
    case 'add_pending_entry':
        if (!is_registered_admin()) {
            echo json_encode(['success' => false, 'message' => 'Authentication required.']);
            exit;
        }

        // Accept both JSON (from $data) and multipart/form-data (from $_POST/$_FILES)
        $title = $_POST['title'] ?? ($data['title'] ?? '');
        $description = $_POST['description'] ?? ($data['description'] ?? '');
        $links = isset($_POST['links'])
            ? json_decode($_POST['links'], true)
            : ($data['links'] ?? []);

        // Image URL or uploaded file
        $image_url = $_POST['image_url'] ?? ($data['image_url'] ?? '');

        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . "/assets/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $filename = time() . "_" . basename($_FILES['image_file']['name']);
            $targetPath = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['image_file']['tmp_name'], $targetPath)) {
                $image_url = "assets/" . $filename;
            }
        }

        if (empty($title) || empty($description) || empty($image_url) || empty($links)) {
            echo json_encode(['success' => false, 'message' => 'Title, description, image, and at least one link are required.']);
            exit;
        }

        $sanitized_entry = [
            'title' => strip_tags(trim($title)),
            'description' => strip_tags(trim($description)),
            'image_url' => filter_var(trim($image_url), FILTER_SANITIZE_URL),
            'links' => []
        ];

        foreach ($links as $link) {
            $sanitized_entry['links'][] = [
                'url' => filter_var(trim($link['url']), FILTER_SANITIZE_URL),
                'platform' => strip_tags(trim($link['platform'])),
                'architecture' => strip_tags(trim($link['architecture']))
            ];
        }

        $new_entry = json_encode($sanitized_entry) . "\n";

        if (file_put_contents(PENDING_LINKS_FILE, $new_entry, FILE_APPEND | LOCK_EX)) {
            echo json_encode(['success' => true, 'message' => 'Entry submitted for review!', 'image_url' => $sanitized_entry['image_url']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save pending link. Check file permissions.']);
        }
        break;

    case 'get_pending_links':
        if (!is_main_owner()) {
            echo json_encode(['success' => false, 'message' => 'Only the main owner can view pending links.']);
            exit;
        }

        $pending_links = [];
        if (file_exists(PENDING_LINKS_FILE)) {
            $lines = file(PENDING_LINKS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $pending_links[] = json_decode($line, true);
            }
        }
        echo json_encode(['success' => true, 'links' => $pending_links]);
        break;

    case 'approve_link':
        if (!is_main_owner()) {
            echo json_encode(['success' => false, 'message' => 'Only the main owner can approve links.']);
            exit;
        }
        
        $index = $data['index'] ?? -1;

        if ($index === -1) {
            echo json_encode(['success' => false, 'message' => 'Invalid index for approval.']);
            exit;
        }

        $pending_links = [];
        if (file_exists(PENDING_LINKS_FILE)) {
            $lines = file(PENDING_LINKS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $pending_links[] = json_decode($line, true);
            }
        }
        
        if (!isset($pending_links[$index])) {
            echo json_encode(['success' => false, 'message' => 'Link not found.']);
            exit;
        }

        $approved_link = $pending_links[$index];

        $links_string = implode(', ', array_map(function($link) {
            return $link['url'] . " (" . $link['platform'] . " - " . $link['architecture'] . ")";
        }, $approved_link['links']));

        $new_entry_text = sprintf(
            "\n***\n%s\n%s\n%s\n%s\n---\n",
            strip_tags($approved_link['title']),
            strip_tags($approved_link['description']),
            filter_var($approved_link['image_url'], FILTER_SANITIZE_URL),
            $links_string
        );
        
        if (file_put_contents(DATABASE_FILE, $new_entry_text, FILE_APPEND | LOCK_EX)) {
            unset($lines[$index]);
            file_put_contents(PENDING_LINKS_FILE, implode("\n", $lines), LOCK_EX);

            echo json_encode(['success' => true, 'message' => 'Link approved and added to the database.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to write to main database. Check file permissions.']);
        }
        break;

    case 'disapprove_link':
        if (!is_main_owner()) {
            echo json_encode(['success' => false, 'message' => 'Only the main owner can disapprove links.']);
            exit;
        }
        
        $index = $data['index'] ?? -1;

        if ($index === -1) {
            echo json_encode(['success' => false, 'message' => 'Invalid index for disapproval.']);
            exit;
        }

        $pending_links = [];
        if (file_exists(PENDING_LINKS_FILE)) {
            $lines = file(PENDING_LINKS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $pending_links[] = json_decode($line, true);
            }
        }
        
        if (!isset($pending_links[$index])) {
            echo json_encode(['success' => false, 'message' => 'Link not found.']);
            exit;
        }

        unset($lines[$index]);
        file_put_contents(PENDING_LINKS_FILE, implode("\n", $lines), LOCK_EX);

        echo json_encode(['success' => true, 'message' => 'Link disapproved and removed.']);
        break;


case 'get_apps':
        if (!is_main_owner()) {
            echo json_encode(['success' => false, 'message' => 'Only the main owner can view apps.']);
            exit;
        }

        $apps = [];
        if (file_exists(DATABASE_FILE)) {
            $lines = file(DATABASE_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                // assuming JSON per line storage for easier editing
                $apps[] = json_decode($line, true) ?: $line;
            }
        }
        echo json_encode(['success' => true, 'apps' => $apps]);
        break;

    case 'update_app':
        if (!is_main_owner()) {
            echo json_encode(['success' => false, 'message' => 'Only the main owner can update apps.']);
            exit;
        }

        $index = $data['index'] ?? -1;
        $updated = $data['app'] ?? null;

        if ($index === -1 || !$updated) {
            echo json_encode(['success' => false, 'message' => 'Invalid index or app data.']);
            exit;
        }

        $lines = file(DATABASE_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!isset($lines[$index])) {
            echo json_encode(['success' => false, 'message' => 'App not found.']);
            exit;
        }

        $lines[$index] = json_encode($updated);
        file_put_contents(DATABASE_FILE, implode("\n", $lines) . "\n", LOCK_EX);

        echo json_encode(['success' => true, 'message' => 'App updated successfully.']);
        break;

    case 'delete_app':
        if (!is_main_owner()) {
            echo json_encode(['success' => false, 'message' => 'Only the main owner can delete apps.']);
            exit;
        }

        $index = $data['index'] ?? -1;
        $lines = file(DATABASE_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (!isset($lines[$index])) {
            echo json_encode(['success' => false, 'message' => 'App not found.']);
            exit;
        }

        unset($lines[$index]);
        file_put_contents(DATABASE_FILE, implode("\n", $lines) . "\n", LOCK_EX);

        echo json_encode(['success' => true, 'message' => 'App deleted successfully.']);
        break;

    case 'add_app_direct':
        if (!is_main_owner()) {
            echo json_encode(['success' => false, 'message' => 'Only the main owner can add apps directly.']);
            exit;
        }

        $app = $data['app'] ?? null;
        if (!$app) {
            echo json_encode(['success' => false, 'message' => 'App data required.']);
            exit;
        }

        $new_entry = json_encode($app) . "\n";
        if (file_put_contents(DATABASE_FILE, $new_entry, FILE_APPEND | LOCK_EX)) {
            echo json_encode(['success' => true, 'message' => 'App added directly to database.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add app.']);
        }
        break;


    case 'logout':
        session_unset();
        session_destroy();
        echo json_encode(['success' => true, 'message' => 'Logged out.']);
        break;

    case 'check_session':
        echo json_encode(['is_main_owner' => is_main_owner(), 'is_admin' => is_registered_admin()]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
        break;
}
?>

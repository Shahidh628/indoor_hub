<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'manager') {
    header('Location: dashboard.php');
    exit;
}

require_once 'includes/db.php'; // Database connection file

$manager_id = $_SESSION['user']['id'];

// Fetch the manager's assigned centres
$stmt = $conn->prepare('SELECT * FROM centres WHERE manager_id = ?');
$stmt->bind_param('i', $manager_id);
$stmt->execute();
$centres = $stmt->get_result();

// Initialize the $centre_ids array
$centre_ids = [];

if ($centres->num_rows > 0) {
    while ($c = $centres->fetch_assoc()) {
        $centre_ids[] = $c['id'];
    }
} else {
    $centre_ids = [];  // No centres assigned to the manager
}

// Handle booking actions (complete/cancel)
if (isset($_GET['action']) && isset($_GET['booking_id'])) {
    $action = $_GET['action'];
    $booking_id = intval($_GET['booking_id']);
    
    // Verify the booking belongs to manager's centres
    $verify_stmt = $conn->prepare("SELECT b.id FROM bookings b 
                                   JOIN time_slots ts ON b.slot_id = ts.id 
                                   WHERE b.id = ? AND ts.centre_id IN (" . implode(',', $centre_ids) . ")");
    $verify_stmt->bind_param('i', $booking_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows > 0) {
        if ($action === 'complete') {
            $update_stmt = $conn->prepare('UPDATE bookings SET status = "completed" WHERE id = ?');
            $update_stmt->bind_param('i', $booking_id);
            $update_stmt->execute();
            $_SESSION['message'] = 'Booking marked as completed.';
        } elseif ($action === 'cancel') {
            $update_stmt = $conn->prepare('UPDATE bookings SET status = "cancelled" WHERE id = ?');
            $update_stmt->bind_param('i', $booking_id);
            $update_stmt->execute();
            $_SESSION['message'] = 'Booking cancelled successfully.';
        }
    } else {
        $_SESSION['message'] = 'You do not have permission to modify this booking.';
    }
    header('Location: manager_dashboard.php');
    exit;
}

// Handle centre description update
if (isset($_POST['update_description'])) {
    $centre_id = $_POST['centre_id'];
    $description = $_POST['description'];
    
    if (in_array($centre_id, $centre_ids)) {
        $stmt = $conn->prepare('UPDATE centres SET description = ? WHERE id = ?');
        $stmt->bind_param('si', $description, $centre_id);
        $stmt->execute();
        $_SESSION['message'] = 'Centre description updated successfully.';
    } else {
        $_SESSION['message'] = 'You cannot update this centre.';
    }
    header('Location: manager_dashboard.php');
    exit;
}

// Handle price update
if (isset($_POST['update_price'])) {
    $centre_id = $_POST['centre_id'];
    $new_price = floatval($_POST['new_price']);
    
    if (in_array($centre_id, $centre_ids) && $new_price > 0) {
        $stmt = $conn->prepare('UPDATE centres SET price_per_slot = ? WHERE id = ?');
        $stmt->bind_param('di', $new_price, $centre_id);
        $stmt->execute();
        $_SESSION['message'] = 'Price updated successfully.';
    } else {
        $_SESSION['message'] = 'Invalid price or you cannot update this centre.';
    }
    header('Location: manager_dashboard.php');
    exit;
}

// Ensure that there are assigned centres before proceeding
if (count($centre_ids) > 0) {
    // Generate placeholders for the query
    $placeholders = implode(',', array_fill(0, count($centre_ids), '?'));
    $types = str_repeat('i', count($centre_ids));

    // SQL query to fetch bookings for the centres assigned to the manager
    $sql = "SELECT b.id AS booking_id, b.booking_date, b.status, ts.date, ts.start_time, ts.end_time, c.name AS centre_name, b.user_id, u.fullname AS user_name, u.email AS user_email
            FROM bookings b
            JOIN time_slots ts ON b.slot_id = ts.id
            JOIN centres c ON ts.centre_id = c.id
            JOIN users u ON b.user_id = u.id
            WHERE ts.centre_id IN ($placeholders)
            ORDER BY ts.date DESC, ts.start_time DESC";

    $stmt2 = $conn->prepare($sql);
    $stmt2->bind_param($types, ...$centre_ids);
    $stmt2->execute();
    $bookings = $stmt2->get_result();
    
    // Get statistics
    $stats_sql = "SELECT 
                    COUNT(*) as total_bookings,
                    SUM(CASE WHEN b.status = 'booked' THEN 1 ELSE 0 END) as pending_bookings,
                    SUM(CASE WHEN b.status = 'completed' THEN 1 ELSE 0 END) as completed_bookings,
                    SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_bookings,
                    SUM(CASE WHEN b.status = 'completed' THEN c.price_per_slot ELSE 0 END) as total_revenue
                  FROM bookings b
                  JOIN time_slots ts ON b.slot_id = ts.id
                  JOIN centres c ON ts.centre_id = c.id
                  WHERE ts.centre_id IN ($placeholders)";
    
    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->bind_param($types, ...$centre_ids);
    $stats_stmt->execute();
    $stats = $stats_stmt->get_result()->fetch_assoc();
} else {
    // If no centres assigned, set defaults
    $bookings = [];
    $stats = [
        'total_bookings' => 0,
        'pending_bookings' => 0,
        'completed_bookings' => 0,
        'cancelled_bookings' => 0,
        'total_revenue' => 0
    ];
}

// Fetch feedback for the manager's centres
$feedback_result = [];
if (count($centre_ids) > 0) {
    $feedback_sql = "SELECT f.*, c.name as centre_name, u.fullname as user_name 
                     FROM feedback f 
                     JOIN centres c ON f.centre_id = c.id 
                     JOIN users u ON f.user_id = u.id 
                     WHERE f.centre_id IN (" . implode(',', $centre_ids) . ") 
                     ORDER BY f.created_at DESC";
    $feedback_result = $conn->query($feedback_sql);
}

// Handle file upload (photos)
if (isset($_POST['upload_photo'])) {
    $centre_id = $_POST['centre_id'];
    if (isset($_FILES['centre_photo'])) {
        $target_dir = "uploads/";
        
        // Create uploads directory if it doesn't exist
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        // Generate unique filename
        $file_extension = pathinfo($_FILES["centre_photo"]["name"], PATHINFO_EXTENSION);
        $unique_filename = uniqid() . '_' . time() . '.' . $file_extension;
        $target_file = $target_dir . $unique_filename;

        // Validate file type
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array(strtolower($file_extension), $allowed_types)) {
            // Check if file is uploaded correctly
            if (move_uploaded_file($_FILES["centre_photo"]["tmp_name"], $target_file)) {
                $stmt = $conn->prepare('UPDATE centres SET photo = ? WHERE id = ?');
                $stmt->bind_param('si', $target_file, $centre_id);
                $stmt->execute();
                $_SESSION['message'] = 'Photo uploaded successfully.';
            } else {
                $_SESSION['message'] = 'Failed to upload photo.';
            }
        } else {
            $_SESSION['message'] = 'Invalid file type. Only JPG, JPEG, PNG, and GIF files are allowed.';
        }
    }
    header('Location: manager_dashboard.php');
    exit;
}

// Handle blocking maintenance date
if (isset($_POST['block_maintenance'])) {
    $centre_id = $_POST['centre_id'];
    $maintenance_date = $_POST['maintenance_date'];

    // Ensure the manager can only block dates for their assigned centre
    if (in_array($centre_id, $centre_ids)) {
        // Check if date is not in the past
        if (strtotime($maintenance_date) >= strtotime(date('Y-m-d'))) {
            $stmt = $conn->prepare('INSERT INTO maintenance_dates (centre_id, maintenance_date) VALUES (?, ?)');
            $stmt->bind_param('is', $centre_id, $maintenance_date);
            $stmt->execute();
            $_SESSION['message'] = 'Maintenance date blocked successfully.';
        } else {
            $_SESSION['message'] = 'Cannot block dates in the past.';
        }
    } else {
        $_SESSION['message'] = 'You cannot block maintenance dates for this centre.';
    }
    header('Location: manager_dashboard.php');
    exit;
}

include 'includes/header.php'; // Include header for the page
?>

<div class="card">
    <h2>Manager Panel</h2>
    <?php if (isset($_SESSION['message'])): ?>
        <div class="success"><?php echo $_SESSION['message']; unset($_SESSION['message']); ?></div>
    <?php endif; ?>
    <p>Welcome, <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?>.</p>

    <!-- Statistics Dashboard -->
    <div class="stats-dashboard">
        <h3>Dashboard Statistics</h3>
        <div class="stats-grid">
            <div class="stat-card">
                <h4>Total Bookings</h4>
                <p class="stat-number"><?php echo $stats['total_bookings']; ?></p>
            </div>
            <div class="stat-card">
                <h4>Pending Bookings</h4>
                <p class="stat-number"><?php echo $stats['pending_bookings']; ?></p>
            </div>
            <div class="stat-card">
                <h4>Completed Bookings</h4>
                <p class="stat-number"><?php echo $stats['completed_bookings']; ?></p>
            </div>
            <div class="stat-card">
                <h4>Total Revenue</h4>
                <p class="stat-number">LKR <?php echo number_format($stats['total_revenue'], 2); ?></p>
            </div>
        </div>
    </div>

    <!-- Centre Management Section -->
    <h3>Your Centres</h3>
    <?php if ($centres->num_rows > 0): ?>
        <?php 
        // Reset the result pointer
        $centres->data_seek(0);
        while ($centre = $centres->fetch_assoc()): 
        ?>
            <div class="centre-card">
                <h4><?php echo htmlspecialchars($centre['name']); ?></h4>
                <p><strong>Location:</strong> <?php echo htmlspecialchars($centre['location']); ?></p>
                <p><strong>Price per slot:</strong> LKR <?php echo number_format($centre['price_per_slot'], 2); ?></p>
                
                <?php if (!empty($centre['photo'])): ?>
                    <div class="centre-photo">
                        <img src="<?php echo htmlspecialchars($centre['photo']); ?>" alt="Centre Photo" style="max-width: 200px; height: auto;">
                    </div>
                <?php endif; ?>

                <!-- Update Centre Description -->
                <h5>Update Centre Description</h5>
                <form method="POST">
                    <input type="hidden" name="centre_id" value="<?php echo $centre['id']; ?>" />
                    <textarea name="description" rows="3" cols="50" placeholder="Enter centre description..."><?php echo htmlspecialchars($centre['description'] ?? ''); ?></textarea><br>
                    <button type="submit" name="update_description">Update Description</button>
                </form>

                <!-- Update Price -->
                <h5>Update Price per Slot</h5>
                <form method="POST">
                    <input type="hidden" name="centre_id" value="<?php echo $centre['id']; ?>" />
                    <input type="number" name="new_price" step="0.01" min="0" value="<?php echo $centre['price_per_slot']; ?>" required>
                    <button type="submit" name="update_price">Update Price</button>
                </form>

                <!-- Upload Photo Section -->
                <h5>Upload Centre Photo</h5>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="centre_id" value="<?php echo $centre['id']; ?>" />
                    <input type="file" name="centre_photo" accept="image/*" required>
                    <button type="submit" name="upload_photo">Upload Photo</button>
                </form>

                <!-- Block Maintenance Date -->
                <h5>Block Maintenance Date</h5>
                <form method="POST">
                    <input type="hidden" name="centre_id" value="<?php echo $centre['id']; ?>" />
                    <input type="date" name="maintenance_date" min="<?php echo date('Y-m-d'); ?>" required>
                    <button type="submit" name="block_maintenance">Block Maintenance Date</button>
                </form>

                <!-- Display blocked maintenance dates -->
                <?php
                $maintenance_stmt = $conn->prepare('SELECT maintenance_date FROM maintenance_dates WHERE centre_id = ? ORDER BY maintenance_date DESC');
                $maintenance_stmt->bind_param('i', $centre['id']);
                $maintenance_stmt->execute();
                $maintenance_dates = $maintenance_stmt->get_result();
                
                if ($maintenance_dates->num_rows > 0):
                ?>
                    <h6>Blocked Maintenance Dates:</h6>
                    <ul class="maintenance-dates">
                        <?php while ($date = $maintenance_dates->fetch_assoc()): ?>
                            <li><?php echo htmlspecialchars($date['maintenance_date']); ?></li>
                        <?php endwhile; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <p>No centres assigned to you yet. Please contact an administrator.</p>
    <?php endif; ?>

    <!-- View Bookings -->
    <h3>Your Bookings</h3>
    <table>
        <thead>
            <tr>
                <th>Centre</th>
                <th>User</th>
                <th>Email</th>
                <th>Date</th>
                <th>Time</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($bookings && $bookings->num_rows > 0): ?>
            <?php while ($booking = $bookings->fetch_assoc()): ?>
                <tr class="booking-row <?php echo $booking['status']; ?>">
                    <td><?php echo htmlspecialchars($booking['centre_name']); ?></td>
                    <td><?php echo htmlspecialchars($booking['user_name']); ?></td>
                    <td><?php echo htmlspecialchars($booking['user_email']); ?></td>
                    <td><?php echo htmlspecialchars($booking['date']); ?></td>
                    <td><?php echo substr($booking['start_time'], 0, 5) . ' - ' . substr($booking['end_time'], 0, 5); ?></td>
                    <td><?php echo ucfirst($booking['status']); ?></td>
                    <td>
                        <!-- Actions for each booking -->
                        <?php if ($booking['status'] === 'booked'): ?>
                            <a href="manager_dashboard.php?action=complete&booking_id=<?php echo $booking['booking_id']; ?>" 
                               onclick="return confirm('Mark this booking as completed?')">Complete</a> |
                            <a href="manager_dashboard.php?action=cancel&booking_id=<?php echo $booking['booking_id']; ?>" 
                               onclick="return confirm('Cancel this booking?')">Cancel</a>
                        <?php else: ?>
                            <span class="no-actions">No actions available</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="7">No bookings found for your centre(s).</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- Feedback Section -->
    <h3>Customer Feedback</h3>
    <?php if ($feedback_result && $feedback_result->num_rows > 0): ?>
        <div class="feedback-section">
            <?php while ($feedback = $feedback_result->fetch_assoc()): ?>
                <div class="feedback-card">
                    <h5><?php echo htmlspecialchars($feedback['centre_name']); ?></h5>
                    <p><strong>Customer:</strong> <?php echo htmlspecialchars($feedback['user_name']); ?></p>
                    <p><strong>Rating:</strong> 
                        <?php 
                        $rating = intval($feedback['rating']);
                        for ($i = 1; $i <= 5; $i++) {
                            echo $i <= $rating ? '★' : '☆';
                        }
                        echo " ($rating/5)";
                        ?>
                    </p>
                    <p><strong>Comment:</strong> <?php echo htmlspecialchars($feedback['comment']); ?></p>
                    <p><small>Date: <?php echo htmlspecialchars($feedback['created_at']); ?></small></p>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <p>No feedback received yet for your centres.</p>
    <?php endif; ?>
</div>

<style>
.stats-dashboard {
    margin: 20px 0;
    padding: 20px;
    background: #f9f9f9;
    border-radius: 8px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    text-align: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.stat-number {
    font-size: 24px;
    font-weight: bold;
    color: #2c3e50;
    margin: 10px 0;
}

.centre-card {
    margin: 20px 0;
    padding: 20px;
    border: 1px solid #ddd;
    border-radius: 8px;
    background: #fafafa;
}

.centre-card h5 {
    color: #2c3e50;
    margin-top: 20px;
}

.maintenance-dates {
    list-style-type: none;
    padding: 0;
}

.maintenance-dates li {
    background: #e74c3c;
    color: white;
    padding: 5px 10px;
    margin: 2px 0;
    border-radius: 4px;
    display: inline-block;
}

.booking-row.completed {
    background-color: #d4edda;
}

.booking-row.cancelled {
    background-color: #f8d7da;
}

.no-actions {
    color: #6c757d;
    font-style: italic;
}

.feedback-section {
    display: grid;
    gap: 15px;
}

.feedback-card {
    background: white;
    padding: 15px;
    border-left: 4px solid #3498db;
    border-radius: 4px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.centre-photo img {
    border-radius: 8px;
    margin: 10px 0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

table {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
}

table th, table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

table th {
    background-color: #f2f2f2;
    font-weight: bold;
}

button {
    background-color: #3498db;
    color: white;
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

button:hover {
    background-color: #2980b9;
}

input[type="text"], input[type="email"], input[type="number"], input[type="date"], input[type="file"], textarea {
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin: 5px;
}
</style>

<?php
include 'includes/footer.php'; // Include footer for the page
?>
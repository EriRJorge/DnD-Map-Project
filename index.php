<?php
// Start the PHP session to handle user authentication
session_start();

// Configuration
define('DEFAULT_MAP_CENTER_X', 5000);
define('DEFAULT_MAP_CENTER_Y', 5000);
define('MIN_ZOOM', 0.5);
define('MAX_ZOOM', 8);
define('PLAYERS_FILE', 'players.json');

// Handle user logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Initialize players from file or create new file if it doesn't exist
function initializePlayers() {
    if (file_exists(PLAYERS_FILE)) {
        $playersJson = file_get_contents(PLAYERS_FILE);
        return json_decode($playersJson, true) ?: [];
    }
    file_put_contents(PLAYERS_FILE, json_encode([]));
    return [];
}

// Save players to file
function savePlayers($players) {
    file_put_contents(PLAYERS_FILE, json_encode($players));
}

// Handle user logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Initialize error message variable
$error = "";

// Handle login requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $players = initializePlayers();
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    // Check player credentials
    if (isset($players[$username]) && password_verify($password, $players[$username])) {
        $_SESSION['username'] = $username;
        $_SESSION['role'] = "player";
        header("Location: index.php");
        exit;
    }
    
    // Check moderator credentials (kept separate from players file for security)
    $users = ["DM" => password_hash("Massa", PASSWORD_DEFAULT)];
    $users_roles = ["DM" => "moderator"];
    if (isset($users[$username]) && password_verify($password, $users[$username])) {
        $_SESSION['username'] = $username;
        $_SESSION['role'] = $users_roles[$username];
        header("Location: index.php");
        exit;
    }
    
    $error = "Invalid username or password.";
}

// Handle registration requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (!$username || !$password) {
        $error = "Please fill out all fields.";
    } else {
        $players = initializePlayers();
        
        // Check if username already exists
        if (isset($players[$username])) {
            $error = "That username is already registered.";
        } else {
            // Hash password and store new player
            $players[$username] = password_hash($password, PASSWORD_DEFAULT);
            savePlayers($players);
            
            // Log the new player in
            $_SESSION['username'] = $username;
            $_SESSION['role'] = "player";
            header("Location: index.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>D&amp;D Ocean Map - <?php echo isset($_SESSION['username']) ? "Welcome" : "Login / Register"; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        /* Reset and Global Styles */
        * { 
            box-sizing: border-box; 
            margin: 0; 
            padding: 0;
        }
        
        body {
            font-family: 'Roboto', sans-serif;
            background: #1a237e;
            color: #fff;
            height: 100vh;
            overflow: hidden;
            line-height: 1.6;
        }

        /* Header Styles */
        header {
            background: rgba(63, 81, 181, 0.95);
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
            backdrop-filter: blur(10px);
            position: relative;
            z-index: 100;
        }

        header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
            margin-bottom: 0.5rem;
        }

        header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        /* Link Styles */
        a {
            color: #ffeb3b;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        a:hover {
            color: #fff;
            text-shadow: 0 0 8px rgba(255,255,255,0.5);
        }

        /* Map Container Styles */
        #map-container {
            width: 100%;
            height: calc(100vh - 90px);
            overflow: hidden;
            position: relative;
            background: linear-gradient(135deg, #0d47a1 0%, #1565c0 100%);
            cursor: grab;
        }

        #map {
            position: absolute;
            width: 10000px;
            height: 10000px;
            background: repeating-linear-gradient(
                45deg,
                #1565c0,
                #1565c0 10px,
                #1976d2 10px,
                #1976d2 20px
            );
            transform-origin: center center;
            transition: transform 0.1s ease-out;
        }

        /* Island Styles */
        .island {
            position: absolute;
            width: 140px;
            height: 140px;
            background: radial-gradient(circle at 40% 30%, #66bb6a, #43a047 70%);
            border: 4px solid #2e7d32;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 500;
            color: #fff;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.4);
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.4);
            padding: 15px;
            text-align: center;
            font-size: 1.1rem;
            z-index: 10;
        }

        .island:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 16px rgba(0,0,0,0.5);
        }

        .island.hidden {
            opacity: 0.6;
            background: radial-gradient(circle at 40% 30%, #9e9e9e, #757575 70%);
            border-color: #616161;
        }

        /* Info Box Styles */
        .info-box {
            position: absolute;
            bottom: 130%;
            left: 50%;
            transform: translateX(-50%) translateY(10px);
            background: rgba(0, 0, 0, 0.95);
            padding: 1rem 1.5rem;
            border-radius: 8px;
            font-size: 0.9rem;
            color: #fff;
            opacity: 0;
            transition: all 0.3s ease;
            white-space: nowrap;
            pointer-events: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.4);
            border: 1px solid rgba(255,255,255,0.1);
            min-width: 200px;
            z-index: 20;
        }

        .info-box:after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 8px solid transparent;
            border-top-color: rgba(0, 0, 0, 0.95);
        }

        .island:hover .info-box {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        /* Control Panel Styles */
        #controls {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 100;
            background: rgba(63, 81, 181, 0.95);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            backdrop-filter: blur(10px);
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .control-btn {
            background: #7986cb;
            color: #fff;
            border: none;
            padding: 0.8rem 1.2rem;
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.3s ease;
            font-weight: 500;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .control-btn:hover {
            background: #5c6bc0;
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        /* Route Lines Styles */
        #route-lines {
            position: absolute;
            top: 0;
            left: 0;
            width: 10000px;
            height: 10000px;
            pointer-events: none;
            z-index: 5;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            padding: 2rem;
            border-radius: 12px;
            width: 500px;
            max-width: 90%;
        }

        
        

    </style>
    <style>
/* Authentication Forms Styling */
.auth-wrapper {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: calc(100vh - 90px);
    padding: 2rem;
    background: linear-gradient(135deg, #0d47a1 0%, #1565c0 100%);
}

.form-box {
    background: rgba(63, 81, 181, 0.95);
    padding: 2rem;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    backdrop-filter: blur(10px);
    width: 100%;
    max-width: 400px;
}

.form-box h2 {
    color: #fff;
    margin-bottom: 1.5rem;
    text-align: center;
    font-size: 1.8rem;
}

.error {
    background: rgba(244, 67, 54, 0.9);
    color: white;
    padding: 1rem;
    border-radius: 4px;
    margin-bottom: 1rem;
    text-align: center;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.form-group input {
    width: 100%;
    padding: 0.8rem;
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 4px;
    background: rgba(255,255,255,0.1);
    color: #fff;
    font-size: 1rem;
    transition: all 0.3s ease;
}

.form-group input:focus {
    background: rgba(255,255,255,0.2);
    outline: none;
    border-color: #ffeb3b;
}

.control-btn {
    width: 100%;
    margin-bottom: 0.5rem;
}

.switch-form-btn {
    background: transparent;
    border: 2px solid #ffeb3b;
    color: #ffeb3b;
}

.switch-form-btn:hover {
    background: rgba(255, 235, 59, 0.1);
}
</style>

<?php if (!isset($_SESSION['username'])): ?>
    <!-- Login Form -->
    <div class="auth-wrapper" id="login-wrapper">
        <div class="form-box">
            <h2>Login</h2>
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="form-group">
                    <label for="login-username">Username:</label>
                    <input type="text" id="login-username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="login-password">Password:</label>
                    <input type="password" id="login-password" name="password" required>
                </div>
                <input type="hidden" name="action" value="login">
                <button type="submit" class="control-btn">Login</button>
                <button type="button" onclick="showRegister()" class="control-btn switch-form-btn">
                    Register as Player
                </button>
            </form>
        </div>
    </div>

    <!-- Registration Form -->
    <div class="auth-wrapper" id="register-wrapper" style="display:none">
        <div class="form-box">
            <h2>Register</h2>
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="form-group">
                    <label for="register-username">Username:</label>
                    <input type="text" id="register-username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="register-password">Password:</label>
                    <input type="password" id="register-password" name="password" required>
                </div>
                <input type="hidden" name="action" value="register">
                <button type="submit" class="control-btn">Register</button>
                <button type="button" onclick="showLogin()" class="control-btn switch-form-btn">
                    Back to Login
                </button>
            </form>
        </div>
    </div>

    <script>
    function showLogin() {
        document.getElementById('register-wrapper').style.display = 'none';
        document.getElementById('login-wrapper').style.display = 'flex';
    }

    function showRegister() {
        document.getElementById('login-wrapper').style.display = 'none';
        document.getElementById('register-wrapper').style.display = 'flex';
    }
    </script>
<?php endif; ?>
</head>
<body>
    <header>
        <h1>D&amp;D Ocean Map</h1>
        <p>
            <?php if(isset($_SESSION['username'])): ?>
                Logged in as <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
                (<a href="?logout=1">Logout</a>)
            <?php else: ?>
                Welcome to the Ocean Map
            <?php endif; ?>
        </p>
    </header>

    <?php if (!isset($_SESSION['username'])): ?>
        <!-- Login Form -->
        <div class="auth-wrapper" id="login-wrapper">
            <div class="form-box">
                <h2>Login</h2>
                <?php if ($error): ?>
                    <div class="error"><?php echo $error; ?></div>
                <?php endif; ?>
                <form method="post">
                    <div class="form-group">
                        <label>Username:</label>
                        <input type="text" name="username" required>
                    </div>
                    <div class="form-group">
                        <label>Password:</label>
                        <input type="password" name="password" required>
                    </div>
                    <input type="hidden" name="action" value="login">
                    <button type="submit" class="control-btn">Login</button>
                </form>
                <button onclick="showRegister()" class="control-btn" style="margin-top: 1rem">
                    Register as Player
                </button>
            </div>
        </div>

        <!-- Registration Form -->
        <div class="auth-wrapper" id="register-wrapper" style="display:none">
            <div class="form-box">
                <h2>Register</h2>
                <form method="post">
                    <div class="form-group">
                        <label>Username:</label>
                        <input type="text" name="username" required>
                    </div>
                    <div class="form-group">
                        <label>Password:</label>
                        <input type="password" name="password" required>
                    </div>
                    <input type="hidden" name="action" value="register">
                    <button type="submit" class="control-btn">Register</button>
                </form>
                <button onclick="showLogin()" class="control-btn" style="margin-top: 1rem">
                    Back to Login
                </button>
            </div>
        </div>
    <?php else: ?>
        <!-- Main Map Interface -->
        <div id="map-container">
            <div id="map">
                <svg id="route-lines"></svg>
            </div>
            <div id="controls">
                <?php if($_SESSION['role'] === 'moderator'): ?>
                    <button class="control-btn" onclick="addIsland()">Add Island</button>
                    <button class="control-btn" onclick="toggleMoveMode()">Toggle Move</button>
                <?php endif; ?>
                <button class="control-btn" onclick="resetZoom()">Reset View</button>
                <button class="control-btn" onclick="zoomIn()">Zoom In</button>
                <button class="control-btn" onclick="zoomOut()">Zoom Out</button>
            </div>
        </div>

        <!-- Island Modal -->
        <div id="island-modal" class="modal">
            <div class="modal-content">
                <h2 id="modal-title">Add Island</h2>
                <div class="form-group">
                    <label>Name:</label>
                    <input type="text" id="island-name">
                </div>
                <div class="form-group">
                    <label>Settlements:</label>
                    <input type="text" id="island-settlements" placeholder="Comma separated">
                </div>
                <div class="form-group">
                    <label>Info:</label>
                    <textarea id="island-info"></textarea>
                </div>
                <div class="form-group">
                    <label>Route:</label>
                    <input type="text" id="island-route">
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="island-visible" checked>
                        Visible to Players
                    </label>
                </div>
                <button class="control-btn" onclick="saveIsland()">Save</button>
                <button class="control-btn" onclick="closeModal()">Cancel</button>
                <button class="control-btn" onclick="deleteIsland()" 
                        style="background: #e53935;" id="delete-btn">Delete</button>
            </div>
        </div>

        <script>
            // Initialize map state
            let islands = localStorage.getItem('islands') ? 
                JSON.parse(localStorage.getItem('islands')) : 
                [
                    
                        {
                        id: 1,
                        name: 'Dragon Isle',
                        x: 4950,
                        y: 4950,
                        settlements: ['Dragon City', 'Flame Port'],
                        info: 'Home to ancient dragons',
                        route: 'Route A',
                        visible: true
                    },
                    {
                        id: 2,
                        name: 'Merchant Haven',
                        x: 5050,
                        y: 4950,
                        settlements: ['Trade Hub', 'Market Town'],
                        info: 'Major trading center',
                        route: 'Route A',
                        visible: true
                    },
                    {
                        id: 3,
                        name: 'Pirate Cove',
                        x: 5000,
                        y: 5050,
                        settlements: ['Smuggler\'s Den', 'Hidden Harbor'],
                        info: 'Notorious pirate hideout',
                        route: 'Route B',
                        visible: true
                    }
                ];

            // State management variables
            let currentUser = "<?php echo $_SESSION['username'] ?? ''; ?>";
            let isModerator = <?php echo isset($_SESSION['role']) && $_SESSION['role'] === 'moderator' ? 'true' : 'false'; ?>;
            let isMoveModeActive = false;
            let selectedIsland = null;
            let editingIslandId = null;
            let currentZoom = 1;
            let currentX = -5000;
            let currentY = -5000;
            let isDragging = false;
            let lastMouseX = 0;
            let lastMouseY = 0;

            // Initialize map position and zoom
            const map = document.getElementById('map');
            updateMapTransform();

            // Map transformation and interaction functions
            function updateMapTransform() {
                // Apply current position and zoom level to map
                map.style.transform = `translate(${currentX}px, ${currentY}px) scale(${currentZoom})`;
            }

            function zoomIn() {
                const prevZoom = currentZoom;
                currentZoom = Math.min(currentZoom * 1.2, 3);
                
                // Adjust position to maintain zoom center
                if (prevZoom !== currentZoom) {
                    const centerX = window.innerWidth / 2;
                    const centerY = window.innerHeight / 2;
                    currentX = centerX - (centerX - currentX) * (currentZoom / prevZoom);
                    currentY = centerY - (centerY - currentY) * (currentZoom / prevZoom);
                }
                
                updateMapTransform();
            }

            function zoomOut() {
                const prevZoom = currentZoom;
                currentZoom = Math.max(currentZoom / 1.2, 0.5);
                
                // Adjust position to maintain zoom center
                if (prevZoom !== currentZoom) {
                    const centerX = window.innerWidth / 2;
                    const centerY = window.innerHeight / 2;
                    currentX = centerX - (centerX - currentX) * (currentZoom / prevZoom);
                    currentY = centerY - (centerY - currentY) * (currentZoom / prevZoom);
                }
                
                updateMapTransform();
            }

            function resetZoom() {
                currentZoom = 1;
                currentX = -5000;
                currentY = -5000;
                updateMapTransform();
            }

            // Island rendering and management
            function renderIslands() {
                console.log('Rendering islands:', islands);
                const map = document.getElementById('map');
                const routeLines = document.getElementById('route-lines');
                
                // Clear existing content while preserving route lines
                map.innerHTML = '';
                map.appendChild(routeLines);
                
                // Render route lines first
                routeLines.innerHTML = '';
                const routes = {};
                islands.forEach(island => {
                    if (!routes[island.route]) routes[island.route] = [];
                    routes[island.route].push(island);
                });
                
                // Draw route connections
                Object.entries(routes).forEach(([route, routeIslands]) => {
                    if (routeIslands.length > 1) {
                        routeIslands.sort((a, b) => a.x - b.x); // Sort islands by x position
                        for (let i = 0; i < routeIslands.length - 1; i++) {
                            const line = document.createElementNS("http://www.w3.org/2000/svg", "line");
                            line.setAttribute("x1", routeIslands[i].x + 70);
                            line.setAttribute("y1", routeIslands[i].y + 70);
                            line.setAttribute("x2", routeIslands[i + 1].x + 70);
                            line.setAttribute("y2", routeIslands[i + 1].y + 70);
                            line.setAttribute("stroke", "#ffeb3b");
                            line.setAttribute("stroke-width", "3");
                            line.setAttribute("stroke-dasharray", "10,5");
                            routeLines.appendChild(line);
                        }
                    }
                });

                // Render islands
                islands.forEach(island => {
                    if (isModerator || island.visible) {
                        const el = document.createElement('div');
                        el.className = `island ${!island.visible ? 'hidden' : ''}`;
                        el.dataset.id = island.id;
                        el.style.left = island.x + "px";
                        el.style.top = island.y + "px";
                        el.innerHTML = island.name;

                        // Create info box
                        const infoBox = document.createElement('div');
                        infoBox.className = "info-box";
                        infoBox.innerHTML = `
                            <strong>${island.name}</strong>
                            <div><em>Settlements:</em> ${island.settlements.join(", ")}</div>
                            <div><em>Info:</em> ${island.info}</div>
                            <div><em>Route:</em> ${island.route}</div>
                        `;
                        el.appendChild(infoBox);

                        // Add event listeners for moderator functions
                        if (isModerator) {
                            el.addEventListener('dblclick', () => openEditModal(island.id));
                            if (isMoveModeActive) {
                                el.addEventListener('mousedown', startDragging);
                            }
                        }

                        map.appendChild(el);
                    }
                });

                // Save islands to localStorage
                localStorage.setItem('islands', JSON.stringify(islands));
            }

            // Island dragging functionality
            function startDragging(e) {
                if (!isMoveModeActive) return;
                e.stopPropagation();
                selectedIsland = e.target;
                const rect = selectedIsland.getBoundingClientRect();
                selectedIsland.startX = e.clientX - rect.left;
                selectedIsland.startY = e.clientY - rect.top;
                document.addEventListener('mousemove', drag);
                document.addEventListener('mouseup', stopDragging);
            }

            function drag(e) {
                if (!selectedIsland) return;
                const x = (e.clientX - selectedIsland.startX) / currentZoom;
                const y = (e.clientY - selectedIsland.startY) / currentZoom;
                selectedIsland.style.left = x + 'px';
                selectedIsland.style.top = y + 'px';
                
                // Update island position in data
                const id = parseInt(selectedIsland.dataset.id);
                const island = islands.find(i => i.id === id);
                if (island) {
                    island.x = x;
                    island.y = y;
                    localStorage.setItem('islands', JSON.stringify(islands));
                    renderIslands();
                }
            }

            function stopDragging() {
                selectedIsland = null;
                document.removeEventListener('mousemove', drag);
                document.removeEventListener('mouseup', stopDragging);
            }

            // Modal management
            function addIsland() {
                editingIslandId = null;
                document.getElementById('modal-title').textContent = 'Add Island';
                document.getElementById('island-name').value = '';
                document.getElementById('island-settlements').value = '';
                document.getElementById('island-info').value = '';
                document.getElementById('island-route').value = '';
                document.getElementById('island-visible').checked = true;
                document.getElementById('delete-btn').style.display = 'none';
                document.getElementById('island-modal').style.display = 'flex';
            }

            function openEditModal(id) {
                const island = islands.find(i => i.id === id);
                if (!island) return;
                
                editingIslandId = id;
                document.getElementById('modal-title').textContent = 'Edit Island';
                document.getElementById('island-name').value = island.name;
                document.getElementById('island-settlements').value = island.settlements.join(', ');
                document.getElementById('island-info').value = island.info;
                document.getElementById('island-route').value = island.route;
                document.getElementById('island-visible').checked = island.visible;
                document.getElementById('delete-btn').style.display = 'inline-block';
                document.getElementById('island-modal').style.display = 'flex';
            }

            function closeModal() {
                document.getElementById('island-modal').style.display = 'none';
            }

            function saveIsland() {
                const name = document.getElementById('island-name').value.trim();
                const settlements = document.getElementById('island-settlements').value
                    .split(',').map(s => s.trim()).filter(s => s);
                const info = document.getElementById('island-info').value.trim();
                const route = document.getElementById('island-route').value.trim();
                const visible = document.getElementById('island-visible').checked;

                if (!name || !settlements.length || !info || !route) {
                    alert('Please fill out all fields');
                    return;
                }

                if (editingIslandId === null) {
                    // Add new island
                    const newIsland = {
                        id: Math.max(0, ...islands.map(i => i.id)) + 1,
                        name,
                        x: 5000,
                        y: 5000,
                        settlements,
                        info,
                        route,
                        visible
                    };
                    islands.push(newIsland);
                } else {
                    // Update existing island
                    const island = islands.find(i => i.id === editingIslandId);
                    if (island) {
                        Object.assign(island, {
                            name,
                            settlements,
                            info,
                            route,
                            visible
                        });
                    }
                }

                localStorage.setItem('islands', JSON.stringify(islands));
                renderIslands();
                closeModal();
            }

            function deleteIsland() {
                if (editingIslandId === null) return;
                
                if (confirm('Are you sure you want to delete this island?')) {
                    islands = islands.filter(i => i.id !== editingIslandId);
                    localStorage.setItem('islands', JSON.stringify(islands));
                    renderIslands();
                    closeModal();
                }
            }

            // Map panning and zooming events
            document.getElementById('map-container').addEventListener('mousedown', e => {
                if (e.target === map || e.target === map.parentElement) {
                    isDragging = true;
                    lastMouseX = e.clientX;
                    lastMouseY = e.clientY;
                    map.style.cursor = 'grabbing';
                }
            });

            document.addEventListener('mousemove', e => {
                if (isDragging) {
                    const deltaX = e.clientX - lastMouseX;
                    const deltaY = e.clientY - lastMouseY;
                    currentX += deltaX;
                    currentY += deltaY;
                    lastMouseX = e.clientX;
                    lastMouseY = e.clientY;
                    updateMapTransform();
                }
            });

            document.addEventListener('mouseup', () => {
                isDragging = false;
                map.style.cursor = 'grab';
            });

            // Add smooth zoom with mouse wheel
            document.getElementById('map-container').addEventListener('wheel', e => {
                e.preventDefault();
                const delta = e.deltaY < 0 ? 1.1 : 0.9;
                const rect = map.parentElement.getBoundingClientRect();
                const mouseX = e.clientX - rect.left;
                const mouseY = e.clientY - rect.top;
                
                const prevZoom = currentZoom;
                currentZoom = Math.max(0.5, Math.min(3, currentZoom * delta));
                
                // Adjust position to zoom toward mouse pointer
                if (prevZoom !== currentZoom) {
                    currentX = mouseX - (mouseX - currentX) * (currentZoom / prevZoom);
                    currentY = mouseY - (mouseY - currentY) * (currentZoom / prevZoom);
                }
                
                updateMapTransform();
            });

            function toggleMoveMode() {
                isMoveModeActive = !isMoveModeActive;
                document.querySelectorAll('.island').forEach(el => {
                    el.style.cursor = isMoveModeActive ? 'move' : 'pointer';
                });
                renderIslands();
            }

            // Authentication UI functions
            function showLogin() {
                document.getElementById('register-wrapper').style.display = 'none';
                document.getElementById('login-wrapper').style.display = 'flex';
            }

            function showRegister() {
                document.getElementById('login-wrapper').style.display = 'none';
                document.getElementById('register-wrapper').style.display = 'flex';
            }

            // Initialize map
            renderIslands();
        </script>
    <?php endif; ?>
</body>
</html>
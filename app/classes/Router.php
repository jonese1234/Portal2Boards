<?php

class Router {
    public $startupTimestamp;

    static $location;
    
    //10MB in bytes
    const maxUploadBytes = 16777216; //1024 * 1024 * 16
    
    //a week in seconds
    const sessionLifetime = 604800; //60 * 60 * 24 * 7

    public function __construct()
    {
        //Start timer for determining page load time
        $this->startupTimestamp = microtime(true);

        //session save path
        ini_set('session.save_path', ROOT_PATH . '/sessions');
        
        //configure cookies and sessions
        ini_set('session.cookie_lifetime', self::sessionLifetime);
        session_set_cookie_params(self::sessionLifetime);

        ini_set('session.gc_maxlifetime', self::sessionLifetime);
        ini_set('session.gc_probability', 1);
        ini_set('session.gc_divisor', 1);

        //start session if logged in
        if (isset($_COOKIE["PHPSESSID"])) {
            session_start();
            setcookie(session_name(), session_id(), time() + self::sessionLifetime, '/');
            
            if (isset($_SESSION["user"])) {
                $_SESSION['user'] = $_SESSION['user']; //keep session variable alive
                SteamSignIn::$loggedInUser = new User($_SESSION["user"]);
            } else {
                //edge case: cookie still exists while session does not exist on the server
                $this->destroySession();
            }
        }

        //TODO: remove
        //disable memory limit.
        ini_set('memory_limit', '-1');
        
        //setting max execution time to 5 minutes for big data
        ini_set('max_execution_time', 300);

        //setting upload limits
        ini_set('post_max_size', (self::maxUploadBytes / (1024 * 1024))."M");
        ini_set('upload_max_filesize', (self::maxUploadBytes / (1024 * 1024))."M");

        //disable debugging
        //Debug::disableAllLogging();

        //Disable error reporting
        error_reporting(1);

        //prepare request URI for processing
        $request = explode('/', $_SERVER['REQUEST_URI']);
        $withoutGet = explode('?', $request[count($request) - 1]);
        $request[count($request) - 1] = $withoutGet[0];
        self::$location = $request;

        $this->processRequest(self::$location);
    }

    public function routeToDefault() {
        header("Location: /changelog");
        exit;
    }
    
    public function routeTo404() {
        View::$page = "404";
        View::$pageData = View::$sitePages[View::$page];
    }

    public function processRequest($location)
    {
        $view = new View();
        $GLOBALS["mapInfo"] = Cache::get("maps");

        //non-page request handling

        //start session and set session cookie when logging in
        if ($location[1] == "login") {

            if ($user = SteamSignIn::validate()) {
                session_start();                
                $_SESSION["user"] = $user;
                if ($auth_hash = Auth::get_auth_hash($user)) {
                    if ($auth_hash == null) {
                        $auth_hash = Auth::gen_auth_hash($user);
                    }
                    $_SESSION["user_auth_hash"] = $auth_hash;
                }
                header("Location: /profile/".$user);
            } else {
                header("Location: /");
            }

            exit;
        }

        //destroy session and session cookie when logging out
        if ($location[1] == "logout") {
            $this->destroySession();
            $this->routeToDefault();
            exit;
        }

        // TODO - NEW API SHIT

        if ($location[1] == "api-v2") {
            // unauthenticated endpoints first
            if ($location[2] == "active-profiles") {
                if (!$_POST || !isset($_POST["months"]) || !is_numeric($_POST["months"])) {
                    echo "Missing or invalid parameters";
                    http_response_code(400);
                    exit;
                }
                $runners = Leaderboard::getActiveRunners(intval($_POST["months"]));
                header("Content-Type: application/json");
                echo json_encode(array(
                    "profiles" => $runners,
                ));
                exit;
            }

            if (!$_POST || !isset($_POST["auth_hash"])) {
                echo "Missing parameters";
                http_response_code(400);
                exit;
            }

            $userId = Auth::test_auth_hash($_POST["auth_hash"]);
            if ($userId == null) {
                echo "User validation failed";
                http_response_code(400);
                exit;
            }

            if ($location[2] == "validate-user") {
                // If we got here, the hash is definitely valid
                header("Content-Type: application/json");
                echo "{\"userId\": \"{$userId}\"}";
                exit;
            }

            if ($location[2] == "auto-submit") {
                if (!isset($_POST["mapId"]) or !is_numeric($_POST["mapId"])) {
                    echo "No valid Map Id Provided";
                    http_response_code(400);
                    exit;
                }

                if (!isset($_POST["score"]) or !is_numeric($_POST["score"])) {
                    echo "No valid score provided";
                    http_response_code(400);
                    exit;
                }

                if (!isset($_FILES["demoFile"])) {
                    echo "No demo provided";
                    http_response_code(400);
                    exit;
                }

                $comment = isset($_POST["comment"]) ? strval($_POST["comment"]) : null;

                $id = Leaderboard::submitChange(
                    $userId,
                    strval($_POST["mapId"]),
                    intval($_POST["score"]),
                    null,
                    $comment,
                    true
                );

                if (array_key_exists("demoFile", $_FILES)) {
                    $file = $_FILES["demoFile"];
                    if ($file["name"] != "") {
                        $this->uploadDemo($file, $id);
                    }
                }

                $change = Leaderboard::getChange($id);
                header("Content-Type: application/json");
                echo json_encode($change);
                exit;
            }

            if ($location[2] == "current-pb") {
                // Get current valid PB
                $pb_row = Leaderboard::getLatestPb($userId, strval($_POST["mapId"]));
                header("Content-Type: application/json");
                if (isset($pb_row)) {
                    echo json_encode($pb_row);
                } else {
                    echo "{}"; // No PB
                }
                exit;
            }

        }

        // API v3 for bots

        if ($location[1] === "api-v3") {
            $authorization = "";
            foreach (getallheaders() as $name => $value) {
                if (strtolower($name) === "authorization") {
                    $authorization = $value;
                    break;
                }
            }

            [$type, $token] = explode(" ", $authorization);

            if ($type !== "Bearer") {
                echo "{\"error\":\"Invalid authorization type.\"}";
                header('Content-Type: application/json');
                http_response_code(400);
                exit;
            }

            if ($token === NULL || !hash_equals(Config::get()->autorender_api_token, $token)) {
                echo "{\"error\":\"Unauthorized.\"}";
                header('Content-Type: application/json');
                http_response_code(401);
                exit;
            }

            if ($location[2] === "set-autorender") {
                $data = json_decode(file_get_contents("php://input"));

                if (!isset($data->changelog_id) || !intval($data->changelog_id)) {
                    echo "{\"error\":\"Invalid changelog_id.\"}";
                    header('Content-Type: application/json');
                    http_response_code(400);
                    exit;
                }

                if (!isset($data->autorender_id) || strlen(strval($data->autorender_id)) !== 11) {
                    echo "{\"error\":\"Invalid autorender_id.\"}";
                    header('Content-Type: application/json');
                    http_response_code(400);
                    exit;
                }

                $affected = Leaderboard::setAutorender(intval($data->changelog_id), strval($data->autorender_id));

                echo "{\"affected\":$affected}";
                header('Content-Type: application/json');
                http_response_code(200);
                exit;
            }

            echo "{\"error\":\"Invalid API.\"}";
            header('Content-Type: application/json');
            http_response_code(400);
            exit;
        }

        //TODO: don't flush connection but rather give a more refined status update to client which can then follow up by polling the back end for successful upload
        //TODO: You could also just don't flush and hope the backend finishes in an acceptable time
        if ($location[1] == "uploadDemo") {
            echo "Uploading Demo";

            header('Connection: close');
            header('Content-Length: 0');
            flush();

            if (isset($_POST["id"]) && isset($_FILES["demoFile"])) {

                if (!is_numeric($_POST["id"])) {
                    exit;
                }

                $change = Leaderboard::getChange($_POST["id"]);
                echo "Uploading Demo to id: ".$_POST["id"];
                if (SteamSignIn::hasProfilePrivileges($change["profile_number"])) {
                    if (array_key_exists("demoFile", $_FILES)) {
                        $file = $_FILES["demoFile"];
                        if ($file["name"] != "") {
                            $this->uploadDemo($file, intval($_POST["id"]));
                        }
                    }
                }
            } else {
                echo "Missing post data!";
            }
            exit;
        }

        if ($location[1] == "setYoutubeID") {
            if (isset($_POST["id"]) && isset($_POST["youtubeID"])) {

                if (!is_numeric($_POST["id"])) {
                    exit;
                }

                if (!preg_match("/^[A-Za-z0-9_\\-?=]*$/", $_POST["youtubeID"])) {
                    exit;
                }

                $change = Leaderboard::getChange($_POST["id"]);
                if (SteamSignIn::hasProfilePrivileges($change["profile_number"])) {
                    Leaderboard::setYoutubeID(intval($_POST["id"]), strval($_POST["youtubeID"]));
                }
            } else {
                echo "Missing post data!";
            }
            exit;
        }

        if ($location[1] == "deleteYoutubeID") {
            if (isset($_POST["id"])) {

                if (!is_numeric($_POST["id"])) {
                    exit;
                }

                $change = Leaderboard::getChange($_POST["id"]);
                if (SteamSignIn::hasProfilePrivileges($change["profile_number"])) {
                    Leaderboard::deleteYoutubeID(intval($_POST["id"]));
                }
            } else {
                echo "Missing post data!";
            }
            exit;
        }

        if ($location[1] == "setComment") {
            if (isset($_POST["id"]) && isset($_POST["comment"])) {

                if (!is_numeric($_POST["id"])) {
                    exit;
                }

                $change = Leaderboard::getChange($_POST["id"]);
                if (SteamSignIn::hasProfilePrivileges($change["profile_number"])) {
                    Leaderboard::setComment(intval($_POST["id"]), strval($_POST["comment"]));
                }
            } else {
                echo "Missing post data!";
            }
            exit;
        }

        if ($location[1] == "deleteComment") {
            if (isset($_POST["id"])) {

                if (!is_numeric($_POST["id"])) {
                    exit;
                }

                $change = Leaderboard::getChange($_POST["id"]);
                if (SteamSignIn::hasProfilePrivileges($change["profile_number"])) {
                    Leaderboard::deleteComment(intval($_POST["id"]));
                }
            } else {
                echo "Missing post data!";
            }
            exit;
        }

        if ($location[1] == "deleteDemo") {
            if (isset($_POST["id"])) {

                if (!is_numeric($_POST["id"])) {
                    exit;
                }

                $change = Leaderboard::getChange($_POST["id"]);
                if (SteamSignIn::hasProfilePrivileges($change["profile_number"])) {
                    $demoManager = new DemoManager();
                    $demoManager->deleteDemo(intval($_POST["id"]));
                    Leaderboard::setDemo(intval($_POST["id"]), 0);
                }
            } else {
                echo "Missing post data!";
            }
            exit;
        }

        if ($location[1] == "getDemo") {
            if (isset($_GET["id"])) {
                if (!is_numeric($_GET["id"])) {
                    exit;
                }
            }

            $demoManager = new DemoManager();
            $demoURL = $demoManager->getDemoURL(intval($_GET["id"]));

            if ($demoURL != NULL) {
                header('Location: ' . $demoURL, true, 303);
                die();
            } else {
                echo "Demo URL cannot be resolved";
            }

            exit;
        }

        if ($location[1] == "setScoreBanStatus") {
            if (isset($_POST["id"]) && isset($_POST["banStatus"])) {

                if (!is_numeric($_POST["id"]) || !is_numeric($_POST["banStatus"])) {
                    exit;
                }

                if (SteamSignIn::loggedInUserIsAdmin()) {
                    Leaderboard::setScoreBanStatus(intval($_POST["id"]), intval($_POST["banStatus"]));
                }
            } else {
                echo "Missing post data!";
            }
            exit;
        }

        if ($location[1] == "submitChange") {
            if (isset($_POST["profileNumber"]) && isset($_POST["chamber"]) && isset($_POST["score"]) && isset($_POST["youtubeID"]) && isset($_POST["comment"])) {

                if (!is_numeric($_POST["profileNumber"])) {
                    exit;
                }

                if (!is_numeric($_POST["chamber"])) {
                    exit;
                }

                if (!is_numeric($_POST["score"])) {
                    exit;
                }

                if (!preg_match("/^[A-Za-z0-9_\\-?=]*$/", $_POST["youtubeID"])) {
                    exit;
                }

                if (SteamSignIn::hasProfilePrivileges($_POST["profileNumber"])) {
                    $id = Leaderboard::submitChange(
                        strval($_POST["profileNumber"]),
                        strval($_POST["chamber"]),
                        intval($_POST["score"]),
                        $_POST["youtubeID"],
                        $_POST["comment"],
                        false
                    );

                    if (array_key_exists("demoFile", $_FILES)) {
                        $file = $_FILES["demoFile"];
                        if ($file["name"] != "") {
                            $this->uploadDemo($file, $id);
                        }
                    }

                    $change = Leaderboard::getChange($id);
                    header("Content-Type: application/json");
                    echo json_encode($change);
                }
            } else {
                echo "Missing post data!";
            }
            exit;
        }

        if ($location[1] == "deleteSubmission") {
            if (isset($_POST["id"])) {

                if (!is_numeric($_POST["id"])) {
                    exit;
                }

                $change = Leaderboard::getChange($_POST["id"]);
                if (SteamSignIn::hasProfilePrivileges($change["profile_number"])) {
                    Leaderboard::deleteSubmission(intval($_POST["id"]));
                    $demoManager = new DemoManager();
                    $demoManager->deleteDemo(intval($_POST["id"]));
                }
            } else {
                echo "Missing post data!";
            }
            exit;
        }

        if ($location[1] == "verifyScore") {
            if (isset($_POST["id"])) {
                if (!is_numeric($_POST["id"])) {
                    exit;
                }

                if (SteamSignIn::loggedInUserIsAdmin()) {
                    Database::query(
                        "UPDATE changelog
                         SET pending = 0
                         WHERE changelog.id = ?",
                        "i",
                        [
                            intval($_POST['id']),
                        ]
                    );
                }
            } else {
                echo "Missing post data!";
            }
            exit;
        }

        if ($location[1] == "fetchNewChamberScores") {
            if (isset($_POST["chamber"])) {

                if (!is_numeric($_POST["chamber"])) {
                    exit;
                }

                Leaderboard::fetchNewData(strval($_POST["chamber"]));
                $chamberBoard = Leaderboard::getBoard(array("chamber" => $_POST["chamber"]));
                Leaderboard::cacheChamberBoards($chamberBoard);
            } else {
                echo "Missing post data!";
            }
            exit;
        }

        if ($location[1] == "fetchNewUserData") {
            if (isset($_POST["profileNumber"])) {

                $profileNumber = $_POST["profileNumber"];

                if (!SteamSignIn::hasProfilePrivileges($profileNumber)) {
                    exit;
                }

                if (!is_numeric($profileNumber)) {
                    exit;
                }

                User::updateProfileData($profileNumber);
                //Leaderboard::cacheProfileURLData();
            } else {
                echo "Missing post data!";
            }
            exit;
        }

        if ($location[1] == "setProfileBanStatus") {
            if (isset($_POST["profileNumber"]) && isset($_POST["banStatus"])) {

                if (!SteamSignIn::loggedInUserIsAdmin()) {
                    exit;
                }

                if (!is_numeric($_POST["profileNumber"]) || !is_numeric($_POST["banStatus"])) {
                    exit;
                }

                if (SteamSignIn::isLoggedIn($_POST["profileNumber"])) {
                    exit;
                }

                Leaderboard::setProfileBanStatus(strval($_POST["profileNumber"]), intval($_POST["banStatus"]));
            } else {
                echo "Missing post data!";
            }
            exit;
        }

        //page request handling
        if ($location[1] == "") {
            $this->routeToDefault();
            exit;
        }
        if (!array_key_exists($location[1], View::$sitePages)) {
            $this->routeTo404();
        } else {
            View::$page = $location[1];
            View::$pageData = View::$sitePages[View::$page];
        }

        if (isset(View::$pageData["js"])) {
            $view->addJsMultiple(View::$pageData["js"]);
        }
        if (isset(View::$pageData["css"])) {
            $view->addCssMultiple(View::$pageData["css"]);
        }

        if ($location[1] === "api" && in_array($_SERVER["REMOTE_ADDR"], ["127.0.0.1"])) {
            if ($location[2] === "refreshCache.php") {
                Debug::$loggingToOutput = true;
                ini_set('memory_limit', '-1');
                Leaderboard::cacheLeaderboard();
                exit;
            }

            if ($location[2] === "fixupScores.php") {
                Debug::initializeFileLogging();
                Debug::$loggingToOutput = true;

                ini_set('memory_limit', '-1');
                ignore_user_abort(true);
                set_time_limit(0);

                $profile_number = $_GET['profile_number'];
                if (!$profile_number || !is_numeric($profile_number)) {
                    echo "missing profile_number";
                    http_response_code(400);
                    exit;
                }

                Leaderboard::fixupScoresForUser($profile_number);
                exit;
            }

            if ($location[2] === "fetchNewScores.php") {
                Debug::initializeFileLogging();
                Debug::$loggingToOutput = true;

                ini_set('memory_limit', '-1');
                ignore_user_abort(true);
                set_time_limit(0);

                Leaderboard::fetchNewData();
                Leaderboard::cacheLeaderboard();
                exit;
            }

            echo "invalid api";
            exit;
        }

        if ($location[1] == "chambers" && isset($location[2])) {
            if ($location[2] == "sp") {
                $view->board = Cache::get("SPChamberBoard");
                View::$pageData["pageTitle"] = "Chambers - Single Player";
            } else if ($location[2] == "coop") {
                $view->board = Cache::get("COOPChamberBoard");
                View::$pageData["pageTitle"] = "Chambers - Cooperative";
            } else {
                $this->routeTo404();
            }
        }

        if ($location[1] == "aggregated" && isset($location[2])) {
            if ($location[2] == "sp") {
                $view->points = Cache::get("SPPointBoard");
                $view->times = Cache::get("SPTimeBoard");
                View::$pageData["pageTitle"] = "Aggregated - Single Player";
                $view->mode = "Single Player";
            } else if ($location[2] == "coop") {
                $view->points = Cache::get("COOPPointBoard");
                $view->times = Cache::get("COOPTimeBoard");
                View::$pageData["pageTitle"] = "Aggregated - Cooperative";
                $view->mode = "Cooperative";
            } else if ($location[2] == "overall") {
                View::$pageData["pageTitle"] = "Aggregated - Overall";
                $view->points = Cache::get("globalPointBoard");
                $view->times = Cache::get("globalTimeBoard");
                $view->mode = "Overall";
            } else if ($location[2] == "chapter") {
                View::$pageData["pageTitle"] = "Aggregated -".$GLOBALS["mapInfo"]["chapters"][$location[3]]["chapterName"];
                $view->mode = $GLOBALS["mapInfo"]["chapters"][$location[3]]["chapterName"];
                $view->points = Cache::get("chapterPointBoard".$location[3]);
                $view->times = Cache::get("chapterTimeBoard".$location[3]);
            } else {
                $this->routeTo404();
            }

            if ((isset($location[3]) && $location[3] == "json") || (isset($location[4]) && $location[4] == "json")) {
                header("Content-Type: application/json");
                echo "{\"Points\":" . json_encode($view->points) . ", \"Times\":" . json_encode($view->times) . "}";
                exit;
            }
        }

        if ($location[1] == "changelog") {

            if (!$_GET) {
                $changelogParams = array("startDate" => (new DateTime('NOW - 7 day'))->format('Y-m-d'), "pending" => "2");
            } else {
                $changelogParams = $_GET;
            }

            $param = $this->prepareChangelogParams($changelogParams);

            $view->changelog = Leaderboard::getChangelog($param);

            if (isset($location[2]) && $location[2] == "json") {
                header("Content-Type: application/json");
                echo json_encode($view->changelog);
                exit;                
            }
        }

        if ($location[1] == "profile" && isset($location[2])) {
            $displayNames = Cache::get("boardnames");
            $id = $location[2];
            if (is_numeric($id)
                && strlen($id) == 17
                && !(isset($location[3]) && $location[3] == "json")
                && isset($displayNames[$location[2]])) {
                header("Location: /profile/" . $displayNames[$location[2]]);
                exit;
            }

            $view->profile = new User($location[2]);
            $view->profile->getProfileData();
            View::$pageData["pageTitle"] = (isset($view->profile->userData->displayName)) ? $view->profile->userData->displayName : "No profile";

            if (isset($location[3]) && $location[3] == "json") {
                // REALLY IMPORTANT: don't show the user's auth hash!
                unset($view->profile->userData->auth_hash);
                header("Content-Type: application/json");
                echo json_encode($view->profile);
                exit;                
            }
        }

        if ($location[1] == "chamber" && isset($location[2])) {
            $view->chamber = Cache::get("chamberBoard" . $location[2]);

            if ($view->chamber) {
                $GLOBALS["chamberID"] = $location[2];
                View::$pageData["pageTitle"] = $GLOBALS["mapInfo"]["maps"][$location[2]]["mapName"];
    
                if (isset($location[3]) && $location[3] == "json") {
                    header("Content-Type: application/json");
                    echo json_encode($view->chamber);
                    exit;
                }
            } else {
                if (isset($location[3]) && $location[3] == "json") {
                    header("Content-Type: application/json");
                    echo "{}";
                    exit;
                } else {
                    $this->routeTo404();
                }
            }
        }

        if ($location[1] == "donators") {
            $data = Database::unsafe_raw(
                "SELECT profile_number
                      , avatar
                      , IFNULL(boardname, steamname) as playername
                      , donation_amount
                 FROM usersnew
                 WHERE title LIKE 'Donator'
                 ORDER BY CAST(donation_amount AS DECIMAL(9, 2)) DESC"
            );

            $view->donators = array();

            while ($row = $data->fetch_assoc()) {
                $row["playername"] = htmlspecialchars($row["playername"]);
                $view->donators[] = $row;
            }

            if (isset($location[2]) && $location[2] == "json") {
                header("Content-Type: application/json");
                echo json_encode($view->donators);
                exit;
            }
        }

        if ($location[1] == "wallofshame") {
            $data = Database::unsafe_raw(
                "SELECT profile_number
                      , avatar
                      , IFNULL(boardname, steamname) as playername
                 FROM usersnew
                 WHERE banned = 1
                 ORDER BY playername"
            );

            $view->wallofshame = array();

            while ($row = $data->fetch_assoc()) {
                $row["playername"] = htmlspecialchars($row["playername"]);
                $view->wallofshame[] = $row;
            }

            if (isset($location[2]) && $location[2] == "json") {
                header("Content-Type: application/json");
                echo json_encode($view->wallofshame);
                exit;
            }
        }

        if ($location[1] == "editprofile") {
            if (isset(SteamSignIn::$loggedInUser)) {
                if ($_POST) {

                    $youtube = NULL;
                    $twitch = $_POST["twitch"];
                    $boardname = NULL;

                    if (strlen($twitch) != 0) {
                        if (!preg_match("/^[A-Za-z0-9_]+$/", $twitch)) {
                            $view->msg = "Twitch username must contain only letters, numbers, and underscores.";
                        }
                    }

                    $boardname = trim($_POST["boardname"]);
                    $boardname = preg_replace('/\s+/', ' ', $boardname);
                    if (strlen($boardname) != 0) {
                        if (!preg_match("/^[A-Za-z 0-9_]+$/", $boardname)
                            || strlen($boardname) > 30
                            || (strlen($boardname) === 17 && is_numeric($boardname))) {
                            $view->msg = "Board name must be at most 30 characters, and contain only letters, numbers, and underscores.";
                        }
                    }

                    if (strlen($_POST["youtube"]) != 0) {
                        if (!preg_match("/^[A-Za-z0-9_\\-\\/:.@]+$/", $_POST["youtube"])) {
                            $view->msg = "Invalid YouTube channel id or username.";
                        } else {
                            if (strpos($_POST["youtube"], '@') !== false) {
                                $youtubePrefix = "/@";
                                $strComponents = explode("@", $_POST["youtube"]);
                                $youtubeChannelID = $strComponents[count($strComponents) - 1];
                            } else if (strpos($_POST["youtube"], '/user/') !== false) {
                                $youtubePrefix = "/user/";
                                $strComponents = explode("/user/", $_POST["youtube"]);
                                $youtubeChannelID = $strComponents[count($strComponents) - 1];
                            } else if (strpos($_POST["youtube"], '/channel/') !== false) {
                                $youtubePrefix = "/channel/";
                                $strComponents = explode("/channel/", $_POST["youtube"]);
                                $youtubeChannelID = $strComponents[count($strComponents) - 1];
                            } else {
                                $youtubePrefix = "/@";
                                $youtubeChannelID = $_POST["youtube"];
                            }
                            $youtube = $youtubePrefix . $youtubeChannelID;
                        }
                    }

                    if (!isset($view->msg)) {                       
                        $error = SteamSignIn::$loggedInUser->saveProfile($twitch, $youtube, $boardname);
                        $view->msg = $error ?? "Profile updated. Wait a minute for the changes to take effect.";
                    }
                }
            } else {
                $this->routeToDefault();
            }
        }

        if ($location[1] == "regenerateAuthHash") {
            if (isset(SteamSignIn::$loggedInUser)) {
                if ($_POST) {
                    Auth::gen_auth_hash(SteamSignIn::$loggedInUser->profileNumber);
                }
                exit;
            } else {
                $this->routeToDefault();
            }
        }

        include(ROOT_PATH . '/views/parts/main.phtml');

    }

    private function uploadDemo($file, int $id) {
        $demoManager = new DemoManager();
        if ($file["size"] < self::maxUploadBytes) {
            $data = file_get_contents($file["tmp_name"]);
            $demoPath = $demoManager->uploadDemo($data, $id);
            Leaderboard::setDemo($id, 1);
            try {
                //Debug::log("Attempting to run execute mdp for $demoPath");
                $demoDetails = $demoManager->getDemoDetails($id);
                MdpManager::Execute($demoPath, $demoDetails);
            } catch (\Throwable $th) {
                //throw $th;
                Debug::log("FAILED to Execute mdp");
                Debug::log($th->__toString());
            }
            return true;
        } else {
            return false;
        }
    }

    private function prepareChangelogParams($params)
    {
        $result = array(
            "id" => ""
            , "chamber" => ""
            , "chapter" => ""
            , "boardName" => ""
            , "profileNumber" => ""
            , "type" => ""
            , "sp" => ""
            , "coop" => ""
            , "wr" => ""
            , "demo" => ""
            , "yt" => ""
            , "maxDaysAgo" => ""
            , "startDate" => ""
            , "endDate" => ""
            , "startRank" => ""
            , "endRank" => ""
            , "submission" => ""
            , "banned" => ""
            , "pending" => "");

        foreach ($params as $key => $val) {
            if (array_key_exists($key, $result)) {
                $result[$key] = $val;
            }
        }
        if ($result["sp"] == "1" && $result["coop"] != "1") {
            $result["type"] = "0";
        } else if ($result["sp"] != "1" && $result["coop"] == "1") {
            $result["type"] = "1";
        }

        return $result;
    }

    private function destroySession() {
        setcookie(session_name(), null, -1, '/');
        session_destroy();
        unset($_SESSION);
    }

}

<?php
	/* remove ill effects of magic quotes */

	if (get_magic_quotes_gpc()) {
		function stripslashes_deep($value) {
			$value = is_array($value) ?
				array_map('stripslashes_deep', $value) : stripslashes($value);
				return $value;
		}

		$_POST = array_map('stripslashes_deep', $_POST);
		$_GET = array_map('stripslashes_deep', $_GET);
		$_COOKIE = array_map('stripslashes_deep', $_COOKIE);
		$_REQUEST = array_map('stripslashes_deep', $_REQUEST);
	}

	$op = $_REQUEST["op"];

	require_once "functions.php";
	if ($op != "share") require_once "sessions.php";
	require_once "modules/backend-rpc.php";
	require_once "sanity_check.php";
	require_once "config.php";
	require_once "db.php";
	require_once "db-prefs.php";

	no_cache_incantation();

	startup_gettext();

	$script_started = getmicrotime();

	$link = db_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

	if (!$link) {
		if (DB_TYPE == "mysql") {
			print mysql_error();
		}
		// PG seems to display its own errors just fine by default.
		return;
	}

	init_connection($link);

	$mode = $_REQUEST["mode"];

	if ((!$op || $op == "rss") && !$_REQUEST["noxml"]) {
			header("Content-Type: application/xml; charset=utf-8");
	} else {
			header("Content-Type: text/plain; charset=utf-8");
	}

	if (ENABLE_GZIP_OUTPUT) {
		ob_start("ob_gzhandler");
	}

	if (SINGLE_USER_MODE) {
		authenticate_user($link, "admin", null);
	}

	$error = sanity_check($link);

	if ($error['code'] != 0 && $op != "logout") {
		print json_encode(array("error" => $error));
		return;
	}

	switch($op) { // Select action according to $op value.
		case "globalUpdateFeeds":
			// Update all feeds needing a update.
			update_daemon_common($link, 0, true, true);
		break; // globalUpdateFeeds

		case "rss":
			$feed = db_escape_string($_REQUEST["id"]);
			$key = db_escape_string($_REQUEST["key"]);
			$is_cat = $_REQUEST["is_cat"] != false;
			$limit = (int)db_escape_string($_REQUEST["limit"]);

			$search = db_escape_string($_REQUEST["q"]);
			$match_on = db_escape_string($_REQUEST["m"]);
			$search_mode = db_escape_string($_REQUEST["smode"]);
			$view_mode = db_escape_string($_REQUEST["view-mode"]);

			if (SINGLE_USER_MODE) {
				authenticate_user($link, "admin", null);
			}

			$owner_id = false;

			if ($key) {
				$result = db_query($link, "SELECT owner_uid FROM
					ttrss_access_keys WHERE access_key = '$key' AND feed_id = '$feed'");

				if (db_num_rows($result) == 1)
					$owner_id = db_fetch_result($result, 0, "owner_uid");
			}

			if ($owner_id) {
				$_SESSION['uid'] = $owner_id;

				generate_syndicated_feed($link, 0, $feed, $is_cat, $limit,
					$search, $search_mode, $match_on, $view_mode);
			} else {
				header('HTTP/1.1 403 Forbidden');
			}
		break; // rss

		case "getUnread":
			$login = db_escape_string($_REQUEST["login"]);
			$fresh = $_REQUEST["fresh"] == "1";

			$result = db_query($link, "SELECT id FROM ttrss_users WHERE login = '$login'");

			if (db_num_rows($result) == 1) {
				$uid = db_fetch_result($result, 0, "id");

				print getGlobalUnread($link, $uid);

				if ($fresh) {
					print ";";
					print getFeedArticles($link, -3, false, true, $uid);
				}

			} else {
				print "-1;User not found";
			}

		break; // getUnread

		case "getProfiles":
			$login = db_escape_string($_REQUEST["login"]);
			$password = db_escape_string($_REQUEST["password"]);

			if (authenticate_user($link, $login, $password)) {
				$result = db_query($link, "SELECT * FROM ttrss_settings_profiles
					WHERE owner_uid = " . $_SESSION["uid"] . " ORDER BY title");

				print "<select style='width: 100%' name='profile'>";

				print "<option value='0'>" . __("Default profile") . "</option>";

				while ($line = db_fetch_assoc($result)) {
					$id = $line["id"];
					$title = $line["title"];

					print "<option value='$id'>$title</option>";
				}

				print "</select>";

				$_SESSION = array();
			}
		break; // getprofiles

		case "pubsub":
			$mode = db_escape_string($_REQUEST['hub_mode']);
			$feed_id = (int) db_escape_string($_REQUEST['id']);
			$feed_url = db_escape_string($_REQUEST['hub_topic']);

			if (!PUBSUBHUBBUB_ENABLED) {
				header('HTTP/1.0 404 Not Found');
				echo "404 Not found";
				return;
			}

			// TODO: implement hub_verifytoken checking

			$result = db_query($link, "SELECT feed_url FROM ttrss_feeds
				WHERE id = '$feed_id'");

			if (db_num_rows($result) != 0) {

				$check_feed_url = db_fetch_result($result, 0, "feed_url");

				if ($check_feed_url && ($check_feed_url == $feed_url || !$feed_url)) {
					if ($mode == "subscribe") {

						db_query($link, "UPDATE ttrss_feeds SET pubsub_state = 2
							WHERE id = '$feed_id'");

						print $_REQUEST['hub_challenge'];
						return;

					} else if ($mode == "unsubscribe") {

						db_query($link, "UPDATE ttrss_feeds SET pubsub_state = 0
							WHERE id = '$feed_id'");

						print $_REQUEST['hub_challenge'];
						return;

					} else if (!$mode) {

						// Received update ping, schedule feed update.
						//update_rss_feed($link, $feed_id, true, true);

						db_query($link, "UPDATE ttrss_feeds SET
							last_update_started = '1970-01-01',
							last_updated = '1970-01-01' WHERE id = '$feed_id' AND
							owner_uid = ".$_SESSION["uid"]);

					}
				} else {
					header('HTTP/1.0 404 Not Found');
					echo "404 Not found";
				}
			} else {
				header('HTTP/1.0 404 Not Found');
				echo "404 Not found";
			}

		break; // pubsub

		case "logout":
			logout_user();
			header("Location: tt-rss.php");
		break; // logout

		case "fbexport":

			$access_key = db_escape_string($_POST["key"]);

			// TODO: rate limit checking using last_connected
			$result = db_query($link, "SELECT id FROM ttrss_linked_instances
				WHERE access_key = '$access_key'");

			if (db_num_rows($result) == 1) {

				$instance_id = db_fetch_result($result, 0, "id");

				$result = db_query($link, "SELECT feed_url, site_url, title, subscribers
					FROM ttrss_feedbrowser_cache ORDER BY subscribers DESC LIMIT 100");

				$feeds = array();

				while ($line = db_fetch_assoc($result)) {
					array_push($feeds, $line);
				}

				db_query($link, "UPDATE ttrss_linked_instances SET
					last_status_in = 1 WHERE id = '$instance_id'");

				print json_encode(array("feeds" => $feeds));
			} else {
				print json_encode(array("error" => array("code" => 6)));
			}
		break; // fbexport

		case "share":
			$uuid = db_escape_string($_REQUEST["key"]);

			$result = db_query($link, "SELECT ref_id, owner_uid FROM ttrss_user_entries WHERE
				uuid = '$uuid'");

			if (db_num_rows($result) != 0) {
				header("Content-Type: text/html");

				$id = db_fetch_result($result, 0, "ref_id");
				$owner_uid = db_fetch_result($result, 0, "owner_uid");

				$_SESSION["uid"] = $owner_uid;
				$article = format_article($link, $id, false, true);
				$_SESSION["uid"] = "";

				print_r($article['content']);

			} else {
				print "Article not found.";
			}

			break;

		default:
			header("Content-Type: text/plain");
			print json_encode(array("error" => array("code" => 7)));
		break; // fallback
	} // Select action according to $op value.

	// We close the connection to database.
	db_close($link);
?>

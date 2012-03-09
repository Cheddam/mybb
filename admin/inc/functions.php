<?php
/**
 * MyBB 1.6
 * Copyright 2010 MyBB Group, All Rights Reserved
 *
 * Website: http://mybb.com
 * License: http://mybb.com/about/license
 *
 * $Id$
 */

/**
 * Logs an administrator action taking any arguments as log data.
 */
function log_admin_action()
{
	global $db, $mybb;

	$data = func_get_args();

	if(count($data) == 1 && is_array($data[0]))
	{
		$data = $data[0];
	}

	if(!is_array($data))
	{
		$data = array($data);
	}

	$log_entry = array(
		"uid" => $mybb->user['uid'],
		"ipaddress" => $db->escape_string(get_ip()),
		"dateline" => TIME_NOW,
		"module" => $db->escape_string($mybb->input['module']),
		"action" => $db->escape_string($mybb->input['action']),
		"data" => $db->escape_string(@serialize($data))
	);

	$db->insert_query("adminlog", $log_entry);
}

/**
 * Redirects the current user to a specified URL.
 *
 * @param string The URL to redirect to
 */
function admin_redirect($url)
{
	if(!headers_sent())
	{
		$url = str_replace("&amp;", "&", $url);
		header("Location: $url");
	}
	else
	{
		echo "<meta http-equiv=\"refresh\" content=\"0; url={$url}\">";
	}
	exit;
}

/**
 * Updates an administration session data array.
 *
 * @param string The name of the item in the data session to update
 * @param mixed The value
 */
function update_admin_session($name, $value)
{
	global $db, $admin_session;
	
	$admin_session['data'][$name] = $value;
	$updated_session = array(
		"data" => $db->escape_string(@serialize($admin_session['data']))
	);
	$db->update_query("adminsessions", $updated_session, "sid='{$admin_session['sid']}'");
}

/**
 * Saves a "flash message" for the current user to be shown on their next page visit.
 *
 * @param string The message to show
 * @param string The type of message to be shown (success|error)
 */
function flash_message($message, $type='')
{
	$flash = array('message' => $message, 'type' => $type);
	update_admin_session('flash_message', $flash);
}

/**
 * Draw pagination for pages in the Admin CP.
 *
 * @param int The current page we're on
 * @param int The number of items per page
 * @param int The total number of items in this collection
 * @param string The URL for pagination of this collection
 * @return string The built pagination
 */
function draw_admin_pagination($page, $per_page, $total_items, $url)
{
	global $mybb, $lang;
	
	if($total_items <= $per_page)
	{
		return;
	}

	$pages = ceil($total_items / $per_page);

	$pagination = "<div class=\"pagination\"><span class=\"pages\">{$lang->pages}: </span>\n";

	if($page > 1)
	{
		$prev = $page-1;
		$prev_page = fetch_page_url($url, $prev);
		$pagination .= "<a href=\"{$prev_page}\" class=\"pagination_previous\">&laquo; {$lang->previous}</a> \n";
	}

	// Maximum number of "page bits" to show
	if(!$mybb->settings['maxmultipagelinks'])
	{
		$mybb->settings['maxmultipagelinks'] = 5;
	}
	
	$max_links = $mybb->settings['maxmultipagelinks'];

	$from = $page-floor($mybb->settings['maxmultipagelinks']/2);
	$to = $page+floor($mybb->settings['maxmultipagelinks']/2);

	if($from <= 0)
	{
		$from = 1;
		$to = $from+$max_links-1;
	}

	if($to > $pages)
	{
		$to = $pages;
		$from = $pages-$max_links+1;
		if($from <= 0)
		{
			$from = 1;
		}
	}

	if($to == 0)
	{
		$to = $pages;
	}


	if($from > 2)
	{
		$first = fetch_page_url($url, 1);
		$pagination .= "<a href=\"{$first}\" title=\"{$lang->page} 1\" class=\"pagination_first\">1</a> ... ";
	}

	for($i = $from; $i <= $to; ++$i)
	{
		$page_url = fetch_page_url($url, $i);
		if($page == $i)
		{
			$pagination .= "<span class=\"pagination_current\">{$i}</span> \n";
		}
		else
		{
			$pagination .= "<a href=\"{$page_url}\" title=\"{$lang->page} {$i}\">{$i}</a> \n";
		}
	}

	if($to < $pages)
	{
		$last = fetch_page_url($url, $pages);
		$pagination .= "... <a href=\"{$last}\" title=\"{$lang->page} {$pages}\" class=\"pagination_last\">{$pages}</a>";
	}

	if($page < $pages)
	{
		$next = $page+1;
		$next_page = fetch_page_url($url, $next);
		$pagination .= " <a href=\"{$next_page}\" class=\"pagination_next\">{$lang->next} &raquo;</a>\n";
	}
	$pagination .= "</div>\n";
	return $pagination;
}

/**
 * Builds a CSV parent list for a particular forum.
 *
 * @param int The forum ID
 * @param string Optional separator - defaults to comma for CSV list
 * @return string The built parent list
 */
function make_parent_list($fid, $navsep=",")
{
	global $pforumcache, $db;
	
	if(!$pforumcache)
	{
		$query = $db->simple_select("forums", "name, fid, pid", "", array("order_by" => "disporder, pid"));
		while($forum = $db->fetch_array($query))
		{
			$pforumcache[$forum['fid']][$forum['pid']] = $forum;
		}
	}
	
	reset($pforumcache);
	reset($pforumcache[$fid]);
	
	foreach($pforumcache[$fid] as $key => $forum)
	{
		if($fid == $forum['fid'])
		{
			if($pforumcache[$forum['pid']])
			{
				$navigation = make_parent_list($forum['pid'], $navsep).$navigation;
			}
			
			if($navigation)
			{
				$navigation .= $navsep;
			}
			$navigation .= $forum['fid'];
		}
	}
	return $navigation;
}

function save_quick_perms($fid)
{
	global $db, $inherit, $canview, $canpostthreads, $canpostreplies, $canpostpolls, $canpostattachments, $cache;

	$permission_fields = array();
	
	$field_list = $db->show_fields_from("forumpermissions");
	foreach($field_list as $field)
	{
		if(strpos($field['Field'], 'can') !== false)
		{
			$permission_fields[$field['Field']] = 1;
		}
	}
	
	// "Can Only View Own Threads" permission is a forum permission only option
	$usergroup_permission_fields = $permission_fields;
	unset($usergroup_permission_fields['canonlyviewownthreads']);
	
	$query = $db->simple_select("usergroups", "gid");
	while($usergroup = $db->fetch_array($query))
	{
		$query2 = $db->simple_select("forumpermissions", $db->escape_string(implode(',', array_keys($permission_fields))), "fid='{$fid}' AND gid='{$usergroup['gid']}'", array('limit' => 1));
		$existing_permissions = $db->fetch_array($query2);
		
		if(!$existing_permissions)
		{
			$query2 = $db->simple_select("usergroups", $db->escape_string(implode(',', array_keys($usergroup_permission_fields))), "gid='{$usergroup['gid']}'", array('limit' => 1));
			$existing_permissions = $db->fetch_array($query2);
		}
		
		// Delete existing permissions
		$db->delete_query("forumpermissions", "fid='{$fid}' AND gid='{$usergroup['gid']}'");

		// Only insert the new ones if we're using custom permissions
		if($inherit[$usergroup['gid']] != 1)
		{
			if($canview[$usergroup['gid']] == 1)
			{
				$pview = 1;
			}
			else
			{
				$pview = 0;
			}
			
			if($canpostthreads[$usergroup['gid']] == 1)
			{
				$pthreads = 1;
			}
			else
			{
				$pthreads = 0;
			}
			
			if($canpostreplies[$usergroup['gid']] == 1)
			{
				$preplies = 1;
			}
			else
			{
				$preplies = 0;
			}
			
			if($canpostpolls[$usergroup['gid']] == 1)
			{
				$ppolls = 1;
			}
			else
			{
				$ppolls = 0;
			}
			
			if(!$preplies && !$pthreads)
			{
				$ppost = 0;
			}
			else
			{
				$ppost = 1;
			}
			
			$insertquery = array(
				"fid" => intval($fid),
				"gid" => intval($usergroup['gid']),
				"canview" => intval($pview),
				"canpostthreads" => intval($pthreads),
				"canpostreplys" => intval($preplies),
				"canpostpolls" => intval($ppolls),
			);
			
			foreach($permission_fields as $field => $value)
			{
				if(array_key_exists($field, $insertquery))
				{
					continue;
				}
				
				$insertquery[$db->escape_string($field)] = intval($existing_permissions[$field]);
			}
			
			$db->insert_query("forumpermissions", $insertquery);
		}
	}
	$cache->update_forumpermissions();
}

/**
 * Checks if a particular user has the necessary permissions to access a particular page.
 *
 * @param array Array containing module and action to check for
 */
function check_admin_permissions($action, $error = true)
{
	global $mybb, $page, $lang, $modules_dir;
	
	if(is_super_admin($mybb->user['uid']))
	{
		return true;
	}
	
	require_once $modules_dir."/".$action['module']."/module_meta.php";
	if(function_exists($action['module']."_admin_permissions"))
	{	
		$func = $action['module']."_admin_permissions";
		$permissions = $func();
		if($permissions['permissions'][$action['action']] && $mybb->admin['permissions'][$action['module']][$action['action']] != 1)
		{
			if($error)
			{
				$page->output_header($lang->access_denied);
				$page->add_breadcrumb_item($lang->access_denied, "index.php?module=home-index");
				$page->output_error("<b>{$lang->access_denied}</b><ul><li style=\"list-style-type: none;\">{$lang->access_denied_desc}</li></ul>");
				$page->output_footer();
				exit;
			}
			else
			{
				return false;
			}
		}
	}
	
	return true;
}

/**
 * Fetches the list of administrator permissions for a particular user or group
 *
 * @param int The user ID to fetch permissions for
 * @param int The (optional) group ID to fetch permissions for
 * @return array Array of permissions for specified user or group
 */
function get_admin_permissions($get_uid="", $get_gid="")
{
	global $db, $mybb;
	
	// Set UID and GID if none
	$uid = $get_uid;
	$gid = $get_gid;
	
	$gid_array = array();
	
	if($uid === "")
	{
		$uid = $mybb->user['uid'];
	}
	
	if(!$gid)
	{
		// Prepare user's groups since the group isn't specified
		$gid_array[] = (-1) * intval($mybb->user['usergroup']);
		
		if($mybb->user['additionalgroups'])
		{
			$additional_groups = explode(',', $mybb->user['additionalgroups']);
			
			if(!empty($additional_groups))
			{
				// Make sure gids are negative
				foreach($additional_groups as $g)
				{
					$gid_array[] = (-1) * abs($g);
				}
			}
		}
	}
	else
	{
		// Group is specified
		// Make sure gid is negative
		$gid_array[] = (-1) * abs($gid);
	}

	// What are we trying to find?
	if($get_gid && !$get_uid)
	{
		// A group only
		
		$options = array(
			"order_by" => "uid",
			"order_dir" => "ASC",
			"limit" => "1"
		);
		$query = $db->simple_select("adminoptions", "permissions", "(uid='-{$get_gid}' OR uid='0') AND permissions != ''", $options);
		return unserialize($db->fetch_field($query, "permissions"));
	}
	else
	{		
		// A user and/or group
		
		$options = array(
			"order_by" => "uid",
			"order_dir" => "DESC"
		);
		
		// Prepare user's groups into SQL format
		$group_sql = '';
		foreach($gid_array as $gid)
		{
			$group_sql .= " OR uid='{$gid}'";
		}
		
		$perms_group = array();
		$query = $db->simple_select("adminoptions", "permissions, uid", "(uid='{$uid}'{$group_sql}) AND permissions != ''", $options);
		while($perm = $db->fetch_array($query))
		{
			$perm['permissions'] = unserialize($perm['permissions']);
			
			// Sorting out which permission is which
			if($perm['uid'] > 0)
			{
				$perms_user = $perm;
				return $perms_user['permissions'];
			}
			elseif($perm['uid'] < 0)
			{
				$perms_group[] = $perm['permissions'];
			}
			else
			{
				$perms_def = $perm['permissions'];
			}
		}
		
		// Figure out group permissions...ugh.
		foreach($perms_group as $gperms)
		{
			if(!isset($final_group_perms))
			{
				// Use this group as the base for admin group permissions
				$final_group_perms = $gperms;
				continue;
			}
			
			// Loop through each specific permission to find the highest permission
			foreach($gperms as $perm_name => $perm_value)
			{
				if($final_group_perms[$perm_name] != '1' && $perm_value == '1')
				{
					$final_group_perms[$perm_name] = '1';
				}
			}
		}

		// Send specific user, or group permissions before default.
		// If user's permission are explicitly set, they've already been returned above.
		if(isset($final_group_perms))
		{
			return $final_group_perms;
		}
		else
		{
			return $perms_def;
		}
	}
}

/**
 * Fetch the iconv/mb encoding for a particular MySQL encoding
 *
 * @param string The MySQL encoding
 * @return string The iconv/mb encoding
 */
function fetch_iconv_encoding($mysql_encoding)
{
    $mysql_encoding = explode("_", $mysql_encoding);
    switch($mysql_encoding[0])
    {
        case "utf8":
            return "utf-8";
			break;
        case "latin1":
            return "iso-8859-1";
			break;
		default:
			return $mysql_encoding[0];
    }
}

/**
 * Adds/Updates a Page/Tab to the permissions array in the adminoptions table
 *
 * @param string The name of the tab that is being affected
 * @param string The name of the page being affected (optional - if not specified, will affect everything under the specified tab)
 * @param integer Default permissions for the page (1 for allowed - 0 for disallowed - -1 to remove)
 */
function change_admin_permission($tab, $page="", $default=1)
{
	global $db;
	
	$query = $db->simple_select("adminoptions", "uid, permissions", "permissions != ''");
	while($adminoption = $db->fetch_array($query))
	{
		$adminoption['permissions'] = unserialize($adminoption['permissions']);
		
		if($default == -1)
		{
			if(!empty($page))
			{
				unset($adminoption['permissions'][$tab][$page]);
			}
			else
			{
				unset($adminoption['permissions'][$tab]);
			}
		}
		else
		{		
			if(!empty($page))
			{
				if($adminoption['uid'] == 0)
				{
					$adminoption['permissions'][$tab][$page] = 0;
				}
				else
				{
					$adminoption['permissions'][$tab][$page] = $default;
				}
			}
			else
			{
				if($adminoption['uid'] == 0)
				{
					$adminoption['permissions'][$tab]['tab'] = 0;
				}
				else
				{
					$adminoption['permissions'][$tab]['tab'] = $default;
				}
			}
		}
		
		$db->update_query("adminoptions", array('permissions' => $db->escape_string(serialize($adminoption['permissions']))), "uid='{$adminoption['uid']}'");
	}
}

/**
 * Checks if we have had too many attempts at logging into the ACP
 *
 * @param integer The uid of the admin to check
 * @param boolean Return an array of the number of attempts and expiry time? (default false)
 * @return mixed Return an array if the second parameter is true, boolean otherwise.
 */
function login_attempt_check_acp($uid=0, $return_num=false)
{
	global $db, $mybb;
	
	$attempts['loginattempts'] = 0;
	
	if($uid > 0)
	{
		$query = $db->simple_select("adminoptions", "loginattempts, loginlockoutexpiry", "uid='".intval($uid)."'", 1);
		$attempts = $db->fetch_array($query);
	}
	
	if($attempts['loginattempts'] <= 0)
	{
		return false;
	}

	if($mybb->settings['maxloginattempts'] > 0 && $attempts['loginattempts'] >= $mybb->settings['maxloginattempts'])
	{
		// Has the expiry dateline been set yet?
		if($attempts['loginlockoutexpiry'] == 0 && $return_num == false)
		{
			$db->update_query("adminoptions", array("loginlockoutexpiry" => TIME_NOW+(intval($mybb->settings['loginattemptstimeout'])*60)), "uid='".intval($uid)."'", 1);
		}
		
		// Are we returning the # of login attempts?
		if($return_num == true)
		{
			return $attempts;
		}
		// Otherwise are we still locked out?
		else if($attempts['loginlockoutexpiry'] > TIME_NOW)
		{
			return true;
		}
	}
	
	return false;
}

/**
 * Checks whether there are any 'security' issues in templates via complex syntax
 *
 * @param string The template to be scanned
 * @return boolean A true/false depending on if an issue was detected
 */
function check_template($template)
{
	// Check to see if our database password is in the template
	if(preg_match("#database'?\\s*\]\\s*\[\\s*'?password#", $template))
	{
		return true;
	}

	// System calls via backtick
	if(preg_match('#\\$\\{`[^`]+`\\}#s', $template))
	{
		return true;
	}

	// Any other malicious acts?
	// Courtesy of ZiNgA BuRgA
	if(preg_match("~\\{\\$.+?\\}~s", preg_replace('~\\{\\$+[a-zA-Z_][a-zA-Z_0-9]*((?:-\\>|\\:\\:)\\$*[a-zA-Z_][a-zA-Z_0-9]*|\\[\s*\\$*([\'"]?)[a-zA-Z_ 0-9 ]+\\2\\]\s*)*\\}~', '', $template)))
	{
		return true;
	}

	return false;
}

/**
 * Provides a function to entirely delete a user's posts, and find the threads attached to them
 *
 * @param integer The uid of the user
 * @param int A UNIX timestamp to delete posts that are older
 * @return array An array of threads to delete, threads/forums to recount
 */
function delete_user_posts($uid, $date)
{
	global $db, $cache;
	$uid = intval($uid);

	// Build an array of posts to delete
	$postcache = array();
	$query = $db->simple_select("posts", "pid", "uid = '".$uid."' AND dateline < '".$date."'");
	while($post = $db->fetch_array($query))
	{
		$postcache[] = $post['pid'];
	}
	
	if(!$db->num_rows($query))
	{
		return false;
	}
	elseif(!empty($postcache))
	{
		// Let's start deleting posts
		$user_posts = implode(",", $postcache);
		$query = $db->query("
			SELECT p.pid, p.visible, f.usepostcounts, t.tid AS thread, t.firstpost, t.fid AS forum
			FROM ".TABLE_PREFIX."posts p
			LEFT JOIN ".TABLE_PREFIX."forums f ON (f.fid=p.fid)
			LEFT JOIN ".TABLE_PREFIX."threads t ON (t.tid=p.tid)
			WHERE p.pid IN ({$user_posts})
		");

		$post_count = 0; // Collect the post number to deduct from the user's postcount
		$thread_list = array();
		$forum_list = array();
		$delete_thread_list = array();
		if(!$db->num_rows($query))
		{
			return false;
		}
		else
		{
			while($post = $db->fetch_array($query))
			{
				if($post['usepostcounts'] != 0 && $post['visible'] != 0)
				{
					++$post_count;
				}

				if($post['pid'] == $post['firstpost'])
				{
					$delete_thread_list[] = $post['thread'];
				}

				if(!in_array($post['thread'], $thread_list) && !in_array($post['thread'], $delete_thread_list))
				{
					$thread_list[] = $post['thread']; // Threads that have been affected by this action, that aren't marked to be deleted
				}
				if(!in_array($post['forum'], $forum_list))
				{
					$forum_list[] = $post['forum']; // Forums that have been affected, too
				}

				// Remove the attachments to this post, then delete the post
				remove_attachments($post['pid']);
				$db->delete_query("posts", "pid = '".$post['pid']."'");
				$db->delete_query("pollvotes", "pid = '".$post['pid']."'"); // Delete pollvotes attached to this post
			}

			$db->update_query("users", array("postnum" => "postnum-".$post_count.""), "uid='".$uid."'", 1, true);

			$to_return = array(
				'to_delete' => $delete_thread_list,
				'thread_update' => $thread_list,
				'forum_update' => $forum_list
			);

			return $to_return;
		}
	}
}
?>

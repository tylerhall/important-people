<?PHP
	// Script: Important People
	// Created: 2009-04-11
 	// Author: Tyler Hall <thall@yahoo-inc.com>
	//         http://clickontyler.com
	//
	// This script grabs a set of Twitter search results
	// and retrieves the author's follower and update counts,
	// i.e., it shows you the important people. Results are
	// color coded and can be exported as a CSV.
	//
	// BTW: I wrote this in about half an hour, so there's very
	// little (read: no) error checking. If the Fail Whale(tm) were
	// to rear it's ugly head, bad things may happen.
	//
	// NOTE: Make sure you create a 'cache' directory in the folder
	// that this script is in and make it writeable by the web server.
	//
	// This script has some pretty heavy, un-optimized, JS loops, i.e.,
	// it's a beast best run in Safari or Chrome.
	//
	// Oh, and this doesn't work at all in IE6 or IE7. And I don't care.
	// If you do, feel free to contribute a patch.


	// Process an user info JavaScript requests...
	if(isset($_GET['username']))
	{
		echo json_encode(user_info($_GET['username']));
		exit;
	}

	$pages_to_fetch = isset($_GET['pages']) ? intval($_GET['pages']) : 10;

	if(isset($_GET['q']))
	{
		$tweets = array();
		$users	= array();
		$q		= urlencode($_GET['q']);
		$page	= 1;
		$url	= "http://search.twitter.com/search.atom?q=$q&rpp=100&page=$page";

		$pages = 0;
		while($url && $pages++ < $pages_to_fetch)
		{
			// Note: we're caching search results. Feel free to turn off if you want real-time results.
			$xmlstr = geturl($url);
			$xml	= simplexml_load_string($xmlstr);
			foreach($xml->entry as $tweet)
			{
				$user = array_shift(explode(' ', (string) $tweet->author->name));
				if($user === $q) continue; // Uncomment to filter out username that match the query
				$tweets[] = array('msg'	 => (string) $tweet->content,
								  'user' => $user,
								  'link' => (string) $tweet->link[0]['href'],
								  'dt'	 => (string) $tweet->published);

				// Only fetch server-side user data when doing a csv dump
				if(isset($_GET['csv']) && !isset($users[$user]))
					$users[$user] = user_info($user);
			}

			// Look for another page of results
			$url = false;
			foreach($xml->link as $link)
			{
				if((string) $link['rel'] == 'next')
					$url = (string) $link['href'];
			}
		}

		if(isset($_GET['csv']))
		{
			header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
			header("Content-Disposition: attachment; filename=" . $_GET['q'] . ".csv");
			header("Content-Type: text/csv");

			echo "message,username,permalink,timestamp,followers,updates\n";
			$fp = fopen('php://output', 'w');
			foreach($tweets as $t)
			{
				unset($users[$t['user']]['username']);
				$arr = array_merge($t, $users[$t['user']]);
				fputcsv($fp, $arr);
			}
			fclose($fp);
			exit;
		}
	}

	// Check our current API limit
	$xmlstr = geturl('http://twitter.com/account/rate_limit_status.xml');
	$xml = simplexml_load_string($xmlstr);
	$api_calls_remaining = (string) $xml->{'remaining-hits'};
	$reset_time = local_time((string) $xml->{'reset-time'});

	// END OF THE MAIN SCRIPT
	// JUST HELPER FUNCTIONS BELOW

	function geturl($url, $cache = true)
	{
		// Please be nice and cache your requests!
		if($cache)
		{
			$fn = 'cache/' . md5($url);
			if(file_exists($fn) && filemtime($fn) > (time() - 3600))
				return file_get_contents($fn);
		}

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		$data = curl_exec($ch);
		curl_close($ch);

		if($cache && !file_put_contents($fn, $data))
			die("Caching is turned on, but it doesn't appear that your cache directory is writable. Tried to write to '$fn'.");

		return $data;
	}

	// Let's face it. Twitter's API is often shitty and they limit you to 100 requests per hour.
	// With that in mind, I offer three solutions for grabbing user data, which can be controlled
	// via the $stupid_twitter parameter. They are...
	//
	// 1) Scrape the data directly from Twitter's website
	// 2) Pull the data using YQL from Yahoo!, which handles caching for us in case twitter.com goes down
	// 3) Use Twitter's API directly
	//
	// I'd suggest using YQL as it's more reliable than the API and a bit nicer on their servers than raw scraping.
	function user_info($username, $stupid_twitter = 'yql')
	{
		if($stupid_twitter === 'scrape') // Scrape public website
		{
			// Please leave the cache turned on so we're at least scraping Twitter "nicely"
			$html = geturl("http://twitter.com/$username");
			$followers = match('/follower_count.*?([0-9,]+)/ms', $html, 1);
			$updates = match('/update_count.*?([0-9,]+)/ms', $html, 1);

			$followers = preg_replace('/[^0-9]/', '', $followers);
			$updates = preg_replace('/[^0-9]/', '', $updates);

			return array('followers' => $followers, 'updates' => $updates, 'username' => $username);
		}
		elseif($stupid_twitter === 'yql') // Go through YQL
		{
			// YQL: select * from html where url="http://twitter.com/{username}" and xpath='//span[@class="stats_count numeric"]'
			$user = urlencode($username);
			$url = "http://query.yahooapis.com/v1/public/yql?q=select%20*%20from%20html%20where%20url%3D%22http%3A%2F%2Ftwitter.com%2F$user%22%20and%0A%20%20%20%20%20%20xpath%3D'%2F%2Fspan%5B%40class%3D%22stats_count%20numeric%22%5D'%0A%20%20%20%20&format=xml";
			$xmlstr = geturl($url);
			$uxml = simplexml_load_string($xmlstr);
			return array('followers' => (string) $uxml->results->span[1], 'updates' => (string) $uxml->results->span[2], 'username' => $username);
		}
		else // Use Twitter's API
		{
			$xmlstr = geturl("http://twitter.com/users/show/" . urlencode($user) . ".xml");
			$uxml = simplexml_load_string($xmlstr);
			return array('followers' => (string) $uxml->followers_count, 'updates' => (string) $uxml->statuses_count, 'username' => $username);
		}
	}

	// Simple wrapper around preg_match()
	function match($regex, $str, $i = 0)
	{
		if(preg_match($regex, $str, $match) == 1)
			return $match[$i];
		else
			return false;
	}

	// Convert a timestamp or date formatted string to a local time
	// I'm sure there's some obscure PHP function I just don't know about
	// that already does this. Right?
	function local_time($dt, $format = 'm/d/Y g:ia')
	{
		if(ctype_digit($dt) !== true)
			$dt = strtotime($dt);

		$arr = localtime($dt, true);
		$local_time = date($format, mktime($arr['tm_hour'], $arr['tm_min'], $arr['tm_sec'], $arr['tm_mon'], $arr['tm_mday'], $arr['tm_year']));
		return $local_time;
	}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
	"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">

<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
	<title>Important People</title>
	<!-- Love us some YUI -->
	<link rel="stylesheet" type="text/css" href="http://yui.yahooapis.com/2.7.0/build/reset-fonts-grids/reset-fonts-grids.css">
	<link rel="stylesheet" type="text/css" href="http://yui.yahooapis.com/2.7.0/build/base/base-min.css">
	<!-- And <3 jQuery, too! -->
	<script type="text/javascript" charset="utf-8" src="http://cdn.clickontyler.com/js/jquery.min.20090210210309.gz.js"></script>

	<style type="text/css" media="screen">
		form, p { text-align:left; }
		th { background-color:#ccc; color:#000; }
		td.dt { white-space:nowrap; }
		td.msg { text-align:left; }

		#progress { width:300px; height:15px; border:1px solid #000; position:relative; margin-bottom:1em; }
		#progress p { color:#000; font-size:77%; position:absolute; width:300px; text-align:center; padding-top:2px; }
		#progress span { display:block; background-color:#99f; width:0%; height:15px; }
	</style>

	<script type="text/javascript" charset="utf-8">
		$(function() {
			var max = 0;
			var completed_rows = 0;
			var pb = $('#progress span'); // progress bar
			var pt = $('#progress p'); // progress text

			// Loop over each data row
			$('tbody tr').each(function(i) {
				// Simple hack that to skip duplicate users
				if($(this).hasClass('done')) return;
				$('tr.u-' + $('td.username', $(this)).text()).addClass('done');

				// Grab the user data from the server
				var that = this;
				$.getJSON('<?PHP echo $_SERVER['PHP_SELF']; ?>?username=' + $('td.username', this).text(), function(json) {
					// Update each instance of that user throughout the table
					$('tr.u-' + json.username).each(function(i) {
						$('td.followers', this).text(json.followers);
						$('td.updates', this).text(json.updates);
						completed_rows++;
					});
					
					// Keep track of the largest follower count so we can use it later
					// to calculate the color coding.
					if(parseInt(json.followers) > max) max = parseInt(json.followers);

					// Progress bar shiznit
					p = (completed_rows / <?PHP echo count($tweets); ?>) * 100;
					pb.css('width', p + '%');
					pt.text(String(completed_rows) + ' / <?PHP echo count($tweets); ?>');

					// Know when to quit
					if(completed_rows == <?PHP echo count($tweets); ?>) {
						colorRows(max);
						pt.text('Done! <?PHP echo count($tweets); ?> total');
					}
				});

			});
		});

		// Given the largest follower count, we can express each user's follower count
		// as a percentage of 255 (shades of color), which is then converted to a hex
		// string and used as a background color of the row.
		function colorRows(max) {
			$('tbody tr').each(function(i) {
				if(max == 0) return;
				var followers = parseInt($('td.followers', this).text());
				followers = 255 - Math.round(followers / max * 255);
				followers = followers.toString(16);
				if(String(followers).length < 2)
					followers = '0' + String(followers);
				$('tr.u-' + $('td.username', this).text()).css('background-color', '#ffff' + followers);
			});
		}
	</script>
</head>
<body>
	<form action="<?PHP echo $_SERVER['PHP_SELF']; ?>" method="get">
		<p>
			<label for="q">Search Query:</label> <input type="text" name="q" value="<?PHP if(isset($_GET['q'])) echo htmlspecialchars($_GET['q']); ?>" id="q">
			<input type="submit" name="btnSubmit" value="Search" id="btnSubmit">
		</p>
		<p><?PHP echo $api_calls_remaining; ?> API calls remaining. Will reset at <?PHP echo $reset_time; ?>.</p>
	</form>

	<?PHP if(isset($_GET['q'])) : ?>
	<p><a href="<?PHP echo $_SERVER['PHP_SELF']; ?>?q=<?PHP echo urlencode($_GET['q']); ?>&amp;csv">Download CSV</a></p>
	<div id="progress">
		<p></p>
		<span></span>
	</div>
	<table>
		<thead>
			<tr>
				<th>Username</th>
				<th>Followers</th>
				<th>Updates</th>
				<th>Date &darr;</th>
				<th>Message</th>
			</tr>
		</thead>
		<tbody>
			<?PHP foreach($tweets as $t) : ?>
			<tr class="u-<?PHP echo $t['user']; ?>">
				<td class="username"><a href="<?PHP echo $t['link']; ?>"><?PHP echo $t['user']; ?></a></td>
				<td class="followers">-</td>
				<td class="updates">-</td>
				<td class="dt"><?PHP echo local_time($t['dt'], 'n/j g:ia'); ?></td>
				<td class="msg"><?PHP echo $t['msg']; ?></td>
			</tr>
			<?PHP endforeach; ?>
		</tbody>
	</table>
	<?PHP endif; ?>
</body>
</html>

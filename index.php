<?PHP
	// Script: Important People
	// Author: Tyler Hall <thall@yahoo-inc.com>
	// Created: 2009-04-11
	//
	// This script grabs all matching Twitter search results
	// and retrieves the author's follower and update counts.
	// Results are color coded and can be downloaded as a CSV.
	//
	// BTW: I wrote this in about half an hour, so there's very
	// little (read: no) error checking. If the fail whale were
	// to rear it's ugly head, bad things may happen.
	//
	// NOTE: Make sure you create a 'cache' directory in the folder
	// that this script is in and make it writeable by the web server.
	//
	// Oh, and this doesn't work at all in IE6 or IE7. I don't care.
	// If you do, feel free to contribute a patch.

	function geturl($url, $cache = false)
	{
		if($cache)
		{
			$fn = md5($url);
			if(file_exists("cache/$fn") && filemtime("cache/$fn") > (time() - 3600))
				return file_get_contents("cache/$fn");
		}

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		$html = curl_exec($ch);
		curl_close($ch);

		if($cache) file_put_contents("cache/$fn", $html);

		return $html;
	}

	// Necessary because Twitter's API rate limit renders any real analysis impossible.
	// (So much data, such a shitty API.)
	function user_info($username, $stupid_twitter = true)
	{
		if($stupid_twitter) // Scrape public website
		{
			// Please leave the cache turned on so we're at least scraping Twitter "nicely"
			$html = geturl("http://twitter.com/$username", true);
			$followers = match('/follower_count.*?([0-9,]+)/ms', $html, 1);
			$updates = match('/update_count.*?([0-9,]+)/ms', $html, 1);

			$followers = preg_replace('/[^0-9]/', '', $followers);
			$updates = preg_replace('/[^0-9]/', '', $updates);

			return array('followers' => $followers, 'updates' => $updates, 'username' => $username);
		}
		elseif($stupid_twitter == 'yql') // Go through YQL
		{
			$user = urlencode($username);
			$url = "http://query.yahooapis.com/v1/public/yql?q=select%20*%20from%20html%20where%20url%3D%22http%3A%2F%2Ftwitter.com%2F$user%22%20and%0A%20%20%20%20%20%20xpath%3D'%2F%2Fspan%5B%40class%3D%22stats_count%20numeric%22%5D'%0A%20%20%20%20&format=xml";
			$xmlstr = geturl($url);
			$uxml = simplexml_load_string($xmlstr);
			return array('followers' => (string) $uxml->results->span[1], 'updates' => (string) $uxml->results->span[2], 'username' => $username);
		}
		else // Use Twitter's API
		{
			$xmlstr = geturl("http://twitter.com/users/show/" . urlencode($user) . ".xml", true);
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

	// Compute the corresponding hex color for a given number of followers
	// Red => White :: Hot => Cold
	function hotness($val)
	{
		global $maximum_hotness;
		if($maximum_hotness == 0) return '#ffffff';
		$val = str_pad(dechex(255 - round($val / $maximum_hotness * 255)), 2, '0');
		return '#ffff' . $val;
	}

	// Convert a timestamp or date formatted string to a local time
	function local_time($dt, $format = 'm/d/Y g:ia')
	{
		if(ctype_digit($dt) !== true)
			$dt = strtotime($dt);

		$arr = localtime($dt, true);
		$local_time = date($format, mktime($arr['tm_hour'], $arr['tm_min'], $arr['tm_sec'], $arr['tm_mon'], $arr['tm_mday'] + 1, $arr['tm_year']));
		return $local_time;
	}

	function format_tweet($str)
	{
		$str = format_links($str);
		$str = preg_replace('/@([a-zA-Z0-9_-]+)/', '<a href="http://twitter.com/$1">@$1</a>', $str);
		return $str;
	}

	function format_links($text)
	{
		$ret = ' ' . $text;
		$ret = preg_replace("#(^|[\n ])([\w]+?://[\w]+[^ \"\n\r\t<]*)#ise", "'\\1<a href=\"\\2\" >\\2</a>'", $ret);
		$ret = preg_replace("#(^|[\n ])((www|ftp)\.[^ \"\t\n\r<]*)#ise", "'\\1<a href=\"http://\\2\" >\\2</a>'", $ret);
		$ret = preg_replace("#(^|[\n ])([a-z0-9&\-_\.]+?)@([\w\-]+\.([\w\-\.]+\.)*[\w]+)#i", "\\1<a href=\"mailto:\\2@\\3\">\\2@\\3</a>", $ret);
		$ret = substr($ret, 1);
		return($ret);
	}




	// Main script begins here...

	if(isset($_GET['username']))
	{
		echo json_encode(user_info($_GET['username'], 'yql'));
		exit;
	}

	$pages_to_fetch = isset($_GET['pages']) ? intval($_GET['pages']) : 1;

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
			$xmlstr = geturl($url, true); // Note: we're caching search results. Feel free to turn off.
			$xml	= simplexml_load_string($xmlstr);
			foreach($xml->entry as $tweet)
			{
				// print_r($tweet);exit;
				$user = array_shift(explode(' ', (string) $tweet->author->name));
				$tweets[] = array('msg'	 => (string) $tweet->content,
								  'user' => $user,
								  'link' => (string) $tweet->link[0]['href'],
								  'dt'	 => (string) $tweet->published);

				// Only fetch user data when doing a csv dump
				if(isset($_GET['csv']) && !isset($users[$user]))
					$users[$user] = user_info($user);
			}

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

			echo "message,username,timestamp,followers,updates\n";
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

	$xmlstr = geturl('http://twitter.com/account/rate_limit_status.xml');
	$xml = simplexml_load_string($xmlstr);
	$api_calls_remaining = (string) $xml->{'remaining-hits'};
	$reset_time = local_time((string) $xml->{'reset-time'});
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
	"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">

<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
	<title>Important People</title>
	<script type="text/javascript" charset="utf-8" src="http://cdn.clickontyler.com/js/jquery.min.20090210210309.gz.js"></script>
	<script type="text/javascript" charset="utf-8">
		$(function() {
			var max = 0;
			var completed = 0;
			$('tr.data').each(function(i) {

				me = $(this);
				if(me.hasClass('done')) return;
				$('tr.u-' + $('td.username', me).text()).addClass('done');
				
				completed++
				var that = this;
				$.getJSON('<?PHP echo $_SERVER['PHP_SELF']; ?>?username=' + $('td.username', this).text(), function(json) {
					$('tr.u-' + json.username).each(function(i) {
						$('td.followers', this).text(json.followers);
						$('td.updates', this).text(json.updates);
					});
					if(parseInt(json.followers, 10) > max) max = parseInt(json.followers, 10);

					// This is a total hack...
					completed--;
					if(completed == 0)
						colorRows(max);
				});

			});
		});

		function colorRows(max) {
			$('tr.data').each(function(i) {
				if(max == 0) return;
				var followers = parseInt($('td.followers', this).text(), 10);
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
		<p><label for="q">Search Query:</label> <input type="text" name="q" value="" id="q"></p>
		<p><?PHP echo $api_calls_remaining; ?> API calls remaining. Will reset at <?PHP echo $reset_time; ?>.</p>
	</form>
	<?PHP if(isset($_GET['q'])) : ?>
	<table>
		<thead>
			<tr>
				<th>Username</th>
				<th>Followers</th>
				<th>Updates</th>
				<th>Date</th>
				<th>Message</th>
			</tr>
		</thead>
		<tbody>
			<?PHP foreach($tweets as $t) : ?>
			<tr class="data u-<?PHP echo $t['user']; ?>" style="background-color:<?PHP echo hotness($users[$t['user']]['followers']); ?>">
				<td class="username"><a href="<?PHP echo $t['link']; ?>"><?PHP echo $t['user']; ?></a></td>
				<td class="followers">-</td>
				<td class="updates">-</td>
				<td><?PHP echo local_time($t['dt'], 'n/j g:ia'); ?></td>
				<td><?PHP echo $t['msg']; ?></td>
			</tr>
			<?PHP endforeach; ?>
		</tbody>
	</table>
	<p><a href="<?PHP echo $_SERVER['PHP_SELF']; ?>?q=<?PHP echo urlencode($_GET['q']); ?>&amp;csv">Download CSV</a></p>
	<?PHP endif; ?>
</body>
</html>

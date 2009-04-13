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
        if($stupid_twitter)
        {
            $html = geturl("http://twitter.com/$username", true);
            $followers = match('/follower_count.*?([0-9,]+)/ms', $html, 1);
            $updates = match('/update_count.*?([0-9,]+)/ms', $html, 1);

			$followers = preg_replace('/[^0-9]/', '', $followers);
			$updates = preg_replace('/[^0-9]/', '', $updates);

            return array('followers' => $followers, 'updates' => $updates);
        }
        else
        {
            $xmlstr = geturl("http://twitter.com/users/show/" . urlencode($user) . ".xml", true);
            $uxml = simplexml_load_string($xmlstr);
            return array('followers' => (string) $uxml->followers_count, 'updates'   => (string) $uxml->statuses_count);
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

    $maximum_hotness = 0;

    if(isset($_GET['q']))
    {
        $tweets = array();
        $users  = array();
        $q      = urlencode($_GET['q']);
        $page   = 1;
        $url    = "http://search.twitter.com/search.atom?q=$q&rpp=100&page=$page";

        // For now, let's be nice and only pull three pages of results...
        $pages = 0;
        while($url && $pages++ < 10)
        {
            $xmlstr = geturl($url, true);
            $xml    = simplexml_load_string($xmlstr);
            foreach($xml->entry as $tweet)
            {
                $user = array_shift(explode(' ', (string) $tweet->author->name));
                $tweets[] = array('msg'  => (string) $tweet->title,
                                  'user' => $user,
                                  'dt'   => (string) $tweet->published);

                if(!isset($users[$user]))
                {
                    $users[$user] = user_info($user);
                    $maximum_hotness = max($maximum_hotness, $users[$user]['followers']);
                }
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
                $arr = array_merge($t, $users[$t['user']]);
                fputcsv($fp, $arr);
            }
            fclose($fp);
            exit;
        }
    }
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">

<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Important People</title>
</head>
<body>
    <form action="<?PHP echo $_SERVER['PHP_SELF']; ?>" method="get">
        <p><label for="q">Search Query:</label> <input type="text" name="q" value="" id="q"></p>
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
            <tr style="background-color:<?PHP echo hotness($users[$t['user']]['followers']); ?>">
                <td><a href="http://twitter.com/<?PHP echo $t['user']; ?>/"><?PHP echo $t['user']; ?></a></td>
                <td><?PHP echo number_format($users[$t['user']]['followers']); ?></td>
                <td><?PHP echo number_format($users[$t['user']]['updates']); ?></td>
                <td><?PHP echo $t['dt']; ?></td>
                <td><?PHP echo $t['msg']; ?></td>
            </tr>
            <?PHP endforeach; ?>
        </tbody>
    </table>
    <p><a href="<?PHP echo $_SERVER['PHP_SELF']; ?>?q=<?PHP echo urlencode($_GET['q']); ?>&amp;csv">Download CSV</a></p>
    <?PHP endif; ?>
</body>
</html>

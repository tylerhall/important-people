<?PHP
    // Process user info JavaScript requests...
    if(isset($_GET['username']))
    {
        echo json_encode(user_info($_GET['username']));
        exit;
    }

    $pages_to_fetch = isset($_GET['pages']) ? intval($_GET['pages']) : 1;

    if(isset($_GET['q']))
    {
        $tweets = array();
        $users  = array();
        $q      = urlencode($_GET['q']);
        $page   = 1;
        $url    = "http://search.twitter.com/search.atom?q=$q&rpp=100&page=$page";

        $pages = 0;
        while($url && $pages++ < $pages_to_fetch)
        {
            // Note: we're caching search results. Feel free to turn off if you want real-time results.
            $xmlstr = geturl($url);
            $xml    = simplexml_load_string($xmlstr);
            foreach($xml->entry as $tweet)
            {
                $user = array_shift(explode(' ', (string) $tweet->author->name));
                if((strtolower($user) != strtolower($_GET['q'])) && (strtolower("@$user") != strtolower($_GET['q'])))
                {
                    $tweets[] = array('msg'  => (string) $tweet->content,
                                      'user' => $user,
                                      'link' => (string) $tweet->link[0]['href'],
                                      'dt'   => (string) $tweet->published);

                    // Only fetch server-side user data when doing a csv dump
                    if(isset($_GET['csv']) && !isset($users[$user]))
                        $users[$user] = user_info($user);
                }
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
    // $xmlstr = geturl('http://twitter.com/account/rate_limit_status.xml');
    // $xml = simplexml_load_string($xmlstr);
    // $api_calls_remaining = (string) $xml->{'remaining-hits'};
    // $reset_time = local_time((string) $xml->{'reset-time'});

    // END OF THE MAIN SCRIPT
    // JUST HELPER FUNCTIONS BELOW

    function geturl($url, $cache = true)
    {
        // Please be nice and cache your requests!
        if($cache)
        {
            $fn = 'cache/' . md5($url);
            if(file_exists($fn) && filemtime($fn) > (time() - 3600) && !isset($_GET['cb']))
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
            // YQL: select * from html where url="http://twitter.com/tylerhall/" and xpath='//span[contains(@class, "_count")]'
            $user = urlencode($username);
            $url = "http://query.yahooapis.com/v1/public/yql?q=select%20*%20from%20html%20where%20url%3D%22http%3A%2F%2Ftwitter.com%2F" . $user . "%2F%22%20and%20xpath%3D'%2F%2Fspan%5Bcontains(%40class%2C%20%22_count%22)%5D'&format=xml";
            $xmlstr = geturl($url);
            $uxml = simplexml_load_string($xmlstr);

            $following = preg_replace('/[^0-9]/', '', (string) $uxml->results->span[0]);
            $followers = preg_replace('/[^0-9]/', '', (string) $uxml->results->span[1]);
            $updates = preg_replace('/[^0-9]/', '', (string) $uxml->results->span[2]);
            return array('following' => $following, 'followers' => $followers, 'updates' => $updates, 'username' => $username);
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
        $local_time = date($format, mktime($arr['tm_hour'], $arr['tm_min'], $arr['tm_sec'], $arr['tm_mon'] + 1, $arr['tm_mday'], $arr['tm_year']));
        return $local_time;
    }
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">

<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <?PHP if(isset($_GET['q'])) : ?>
    <title>Important People - (<?PHP echo htmlspecialchars($_GET['q']); ?>)</title>
    <?PHP else : ?>
    <title>Important People</title>
    <?PHP endif; ?>
    <link rel="search" type="application/opensearchdescription+xml" title="Influencers" href="http://sideline.yahoo.com/influencers/opensearch.xml" />
    <!-- Love us some YUI -->
    <link rel="stylesheet" type="text/css" href="http://yui.yahooapis.com/2.7.0/build/reset-fonts-grids/reset-fonts-grids.css">
    <link rel="stylesheet" type="text/css" href="http://yui.yahooapis.com/2.7.0/build/base/base-min.css">
    <!-- And <3 jQuery, too! -->
    <script type="text/javascript" charset="utf-8" src="http://cdn.clickontyler.com/js/jquery.min.20090210210309.gz.js"></script>

    <style type="text/css" media="screen">
        h1, h2, form, p, blockquote { text-align:left; }
        h1 { color:#7b0099; }
        h1 sup { font-size:77%; color:#aaa; }
        blockquote { font-family:courier; }
        th { background-color:#ccc; color:#000; }
        td.dt { white-space:nowrap; }
        td.msg { text-align:left; }

        #progress { width:300px; height:15px; border:1px solid #000; position:relative; margin-bottom:1em; }
        #progress p { color:#000; font-size:77%; position:absolute; width:300px; text-align:center; padding-top:2px; }
        #progress span { display:block; background-color:#99f; width:0%; height:15px; }
    </style>

    <script type="text/javascript" charset="utf-8">
        var completed_rows = 0;
        var unique_users = 0;
        var unique_followers = 0;

        $(function() {
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
                    unique_users++;
                    if(json.followers.length > 0)
                        unique_followers += parseInt(json.followers);

                    // Update each instance of that user throughout the table
                    $('tr.u-' + json.username).each(function(i) {
                        $('td.followers', this).text(json.followers);
                        $('td.following', this).text(json.following);
                        $('td.updates', this).text(json.updates);
                        completed_rows++;
                    });

                    // Progress bar shiznit
                    p = (completed_rows / <?PHP echo count($tweets); ?>) * 100;
                    pb.css('width', p + '%');
                    pt.text(String(completed_rows) + ' / <?PHP echo count($tweets); ?>');

                    // Know when to quit
                    if(completed_rows == <?PHP echo count($tweets); ?>) {
                        colorRows();
                        pt.text('Done! <?PHP echo count($tweets); ?> total');
                        $('th.username').text('Users (' + addCommas(unique_users) + ')');
                        $('th.followers').text('Followers (' + addCommas(unique_followers) + ')');
                    }
                });

            });
        });

        function colorRows() {
            var stdev = stDev();
            var cfactor = 100 / ((unique_followers / unique_users) + stdev); // 100 should be 255, but that's too bright
            var lowcut = (unique_followers / unique_users) - stdev / 4;
            var highcut = (unique_followers / unique_users) + stdev / 2;
            var tdmcut = (unique_followers / unique_users) + stdev / 4;
            var color;
            var followers;

            $('tbody tr').each(function(i) {
                followers = parseInt($('td.followers', this).text());

                if(followers > tdmcut) {
                    if(parseInt($('td.following', this).text()) > (followers * 0.75)) {
                        // $(this).css('background-color', '#00cc00');
                        $(this).fadeTo(0, .4);
                        return;
                    }
                }

                if(followers < lowcut)
                    color = 'ffffff';
                else if(followers > highcut)
                    color = 'ffff00';
                else {
                    followers = 255 - Math.round(followers * cfactor);
                    followers = followers.toString(16);
                    if(followers.length < 2)
                        followers = '0' + followers;
                    color = 'ff' + followers + 'ff';
                }

                $(this).css('background-color', '#' + color);
            });
        }

        // Calculate standard deviation of Followers column
        function stDev() {
            var avg = unique_followers / unique_users;
            var sum = 0;
            var f;

            $('tbody tr').removeClass('done').each(function(i) {
                if($(this).hasClass('done')) return;
                $('tr.u-' + $('td.username', $(this)).text()).addClass('done');
                f = parseFloat($('td.followers', $(this)).text());
                if(!isNaN(f)) sum += Math.pow(Math.abs(f - avg), 2);
            });
            return Math.sqrt(sum / (unique_users - 1));
        }

        // From http://www.mredkj.com/javascript/nfbasic.html
        function addCommas(nStr) {
            nStr += '';
            x = nStr.split('.');
            x1 = x[0];
            x2 = x.length > 1 ? '.' + x[1] : '';
            var rgx = /(\d+)(\d{3})/;
            while (rgx.test(x1)) {
                x1 = x1.replace(rgx, '$1' + ',' + '$2');
            }
            return x1 + x2;
        }
    </script>
</head>
<body>
    <h1>Important People <sup>so beta it's not even alpha</sup></h1>

    <p>During a conversation at work, <a href="http://twitter.com/mulls/">@mulls</a> wanted a quick way to see who are the most influential people tweeting about a specific topic. <a href="http://twitter.com/chadauld/">@chadauld</a> and I came up with the simple metric of ranking users by their follower count. And this is the result.</p>

    <?PHP if(!isset($_GET['q'])) : ?>
    <p>Users we consider influential are colored bright yellow. Mid-range users are pink, gradiating down to users that no one cares about in white, and ordered by date &mdash; most recent on top.</p>

    <p>For the math nerds in the house, we deem important users to be those where</p>
    <blockquote>followers &gt; &#956; + &#963; / 2</blockquote>
    <p>and un-important users are where</p>
    <blockquote>followers &lt; &#956; - &#963; / 4</blockquote>

    <p>That said, given more time, we should probably develop a better metric. Take into account their updates per day, or how many followers *their* followers have. Something like that.</p>

    <p>Anyway, because Twitter's API ocasionally doesn't respond quickly enough, we load the follower counts on the client side by making callbacks to <a href="http://developer.yahoo.com/yql/">Yahoo!'s YQL service</a>, which pulls the data for us and also serves as a proxy for when Twitter goes down.</p>

    <p>This was written in a couple hours, so don't hate too much. (We know it doesn't currently work in Internet Explorer.) For this demo we've limited the results to two pages of data. Feel free to <a href="http://github.com/tylerhall/important-people/tree/master">download and run it on your own box</a> to get a complete set of results.</p>

    <p><strong>Update 4/18:</strong> We've added an experimental new feature which attempts to highlight (er...ignore) users that we detect are "spammy". Our reason for doing this it to try and filter out users that are clearly trying to game Twitter. You'll see that these users are greyed out. And here's the formula we're using to detect them:</p>
    <blockquote>(followers &gt; &#956; + &#963; / 4) &amp;&amp; (following &gt; followers * 0.75)</blockquote>
    <?PHP endif; ?>

    <h2>Whatcha think?</h2>

    <p>Do you find this hack useful? We'd love to get your feedback as we're thinking of incorporating this functionality directly into Sideline. Let us know! Feedback is welcome either at <a href="http://twitter.com/tylerhall/">@tylerhall</a>, <a href="http://twitter.com/chadauld/">@chadauld</a>, or <a href="http://twitter.com/ysideline/">@ysideline</a>.</p>

    <h1>Search</h1>
    <form action="<?PHP echo $_SERVER['PHP_SELF']; ?>" method="get">
        <p>
            <label for="q">Search Query:</label> <input type="text" name="q" value="<?PHP if(isset($_GET['q'])) echo htmlspecialchars($_GET['q']); ?>" id="q">
            <input type="submit" name="btnSubmit" value="Search" id="btnSubmit">
            <a href="<?PHP echo $_SERVER['PHP_SELF']; ?>">Clear Results</a>
        </p>
        <p>Can't think of anything to search for? <a href="<?PHP echo $_SERVER['PHP_SELF']; ?>?q=gruber">Here</a> <a href="<?PHP echo $_SERVER['PHP_SELF']; ?>?q=tylerhall">are</a> <a href="<?PHP echo $_SERVER['PHP_SELF']; ?>?q=yahoo">a</a> <a href="<?PHP echo $_SERVER['PHP_SELF']; ?>?q=sideline">few</a> <a href="<?PHP echo $_SERVER['PHP_SELF']; ?>?q=nashville">examples</a>.</p>
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
                <th class="username">Users</th>
                <th class="followers">Followers</th>
                <th class="following">Following</th>
                <th class="updates">Updates</th>
                <th class="dt">Date &darr;</th>
                <th class="msg">Message</th>
            </tr>
        </thead>
        <tbody>
            <?PHP foreach($tweets as $t) : ?>
            <tr class="u-<?PHP echo $t['user']; ?>">
                <td class="username"><a href="<?PHP echo $t['link']; ?>"><?PHP echo $t['user']; ?></a></td>
                <td class="followers">-</td>
                <td class="following">-</td>
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

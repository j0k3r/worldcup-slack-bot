<?php

/**
 * WorldCup Bot for Slack.
 *
 * It uses the unofficial FIFA json API (the one used for their mobile app iOS/Android).
 * It will post a message :
 *   - when a matche starts
 *   - for red/yellow card
 *   - for the half time and end time
 *   - and of course, for every goal
 *
 * You will need a token from Slack.
 * Jump at https://api.slack.com/ under the "Authentication" part and you will find your token.
 *
 * @author j0k <jeremy.benoist@gmail.com>
 * @license MIT
 */

/**
 * All the configuration are just below
 */

// Slack stuff
const SLACK_TOKEN      = 'XXXXXXXXXXXXXXXXXXXXXXXXXX';
const SLACK_CHANNEL    = '#worldcup';
const SLACK_BOT_NAME   = 'WorldCup Bot';
const SLACK_BOT_AVATAR = 'http://i.imgur.com/dZcA2y8.png';

const USE_PROXY     = false;
const PROXY         = 'http://myproxy:3128';
// If a proxy authentification is needed, set PROXY_USERPWD to "user:password"
const PROXY_USERPWD = false;

/**
 * Below this line, you should modify at your own risk
 */

function getUrl($url)
{
  if (!USE_PROXY)
  {
    return file_get_contents($url);
  }

  $ch = curl_init($url);
  $options = array(
    CURLOPT_HEADER => 0,
    CURLOPT_TIMEOUT => 3,
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_FOLLOWLOCATION => 1,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_PROXY => PROXY,
  );

  if (PROXY_USERPWD)
  {
    $options[CURLOPT_PROXYUSERPWD] = PROXY_USERPWD;
  }

  curl_setopt_array($ch, $options);

  $response = curl_exec($ch);
  if ($response !== false)
  {
    curl_close($ch);
    return $response;
  }

  var_dump(curl_error($ch));
  curl_close($ch);
  die();
}

function postToSlack($text, $attachments_text = '')
{
  $slackUrl = 'https://slack.com/api/chat.postMessage?token='.SLACK_TOKEN.
    '&channel='.urlencode(SLACK_CHANNEL).
    '&username='.urlencode(SLACK_BOT_NAME).
    '&icon_url='.SLACK_BOT_AVATAR.
    '&unfurl_links=1&parse=full&pretty=1'.
    '&text='.urlencode($text);

  if ($attachments_text)
  {
    $slackUrl .= '&attachments='.urlencode('[{"text": "'.$attachments_text.'"}]');
  }

  var_dump(getUrl($slackUrl));
}

$dbFile = './worldCupDB.json';

$db = json_decode(file_get_contents($dbFile), true);
$response = json_decode(getUrl('http://live.mobileapp.fifa.com/api/wc/matches'), true);

if (!isset($response['data']['group']))
{
  var_dump('data>group not good');
  die();
}

// find live matches
foreach ($response['data']['group'] as $match)
{
  if (true === $match['b_Live'] && !in_array($match['n_MatchID'], $db['live_matches']))
  {
    // yay new match !
    $db['live_matches'][] = $match['n_MatchID'];
    $db[$match['n_MatchID']] = array('last_update' => microtime());

    // notify slack & save data
    postToSlack(':zap: Le match '.$match['c_HomeTeam_fr'].' / '.$match['c_AwayTeam_fr'].' commence ! '.$match['c_ShareURL_en']);
    file_put_contents($dbFile, json_encode($db));
    return;
  }
  elseif (in_array($match['n_MatchID'], $db['live_matches']))
  {
    $db[$match['n_MatchID']]['score'] = $match['c_HomeTeam_fr'].' *'.$match['c_Score'].'* '.$match['c_AwayTeam_fr'];
  }
}

$nbLiveMatches = count($db['live_matches']);

// post update on live matches
foreach ($db['live_matches'] as $key => $liveMatch)
{
  $response = json_decode(getUrl('http://live.mobileapp.fifa.com/api/wc/match/'.$liveMatch.'/fr/blog'), true);

  if (!isset($response['data']['posts']))
  {
    var_dump('data>posts not good');
    continue;
  }

  // match isn't live
  if (false === $response['data']['b_Live'])
  {
    unset($db['live_matches'][$key]);
    unset($db[$liveMatch]);
    continue;
  }

  $posts = $response['data']['posts'];

  // extract match teams
  $currentMatch = substr($response['data']['c_BlogName'], strlen('FWC 2014 - '), strpos($response['data']['c_BlogName'], ' [idmatch:')-strlen(' [idmatch:')-1);

  // sort posts by "date"
  krsort($posts);

  foreach ($posts as $post)
  {
    // in case of something new happens
    if (isset($post['data']['c_ActionShort']) && 'Event' == $post['c_Type'] && $post['d_Date'] > $db[$liveMatch]['last_update'])
    {
      $text = $post['data']['c_Text'];

      // handle multiple live match
      $preText = '';
      if ($nbLiveMatches > 1)
      {
        $preText = '_'.$currentMatch.'_ ';
      }

      switch ($post['data']['c_ActionShort'])
      {
        // yellow card
        case 'Y':
          postToSlack($preText.':collision: Carton jaune !', $text);
          break;

        // red card and red card after two yellow
        case 'R':
        case 'R2Y':
          postToSlack($preText.':collision: Carton rouge !', $text);
          break;

        // goal, own goal, penalty goal
        case 'G':
        case 'OG':
        case 'PG':
          $extraInfos = '';
          if ('OG' == $post['data']['c_ActionShort'])
          {
            $extraInfos = ' _(contre son camp)_ ';
          }
          elseif ('PG' == $post['data']['c_ActionShort'])
          {
            $extraInfos = ' _(sur penalty)_ ';
          }

          postToSlack($preText.':soccer: BUUUUUT! '.$extraInfos.' '.$db[$liveMatch]['score'], $text);
          break;

        // half time, end game
        case 'End':
          if ('1H' == $post['data']['c_ActionPhase'])
          {
            // half time
            postToSlack($preText.':toilet: '.$text);
          }
          elseif ('2H' == $post['data']['c_ActionPhase'])
          {
            // end game
            postToSlack($preText.':no_good: '.$text, $db[$liveMatch]['score']);

            // remove match
            unset($db['live_matches'][$key]);
            unset($db[$liveMatch]);
          }
          break;
      }
    }
  }

  if (isset($db[$liveMatch]))
  {
    $db[$liveMatch]['last_update'] = $post['d_Date'];
  }
}

file_put_contents($dbFile, json_encode($db));

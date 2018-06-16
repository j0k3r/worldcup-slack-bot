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

// Set to the language for updates
const LOCALE = 'en-GB'; // fr-FR, en-GB

$language = array(
    'fr-FR' => array(
        'Le match',
        'commence',
        'Carton jaune',
        'Carton rouge',
        'contre son camp',
        'sur penalty',
        'BUUUUUT',
        'penalty manquée',
    ),
    'en-GB' => array(
        'The match between',
        'has started',
        'yellow card',
        'red card',
        'own goal',
        'penalty',
        'GOOOOAL',
        'missed penalty',
    )
);

// FIFA 2018 World Cup

const ID_COMPETITION=17;
const ID_SEASON=254645;

// FIFA API Match Statuses
const MATCH_STATUS_FINISHED = 0;
const MATCH_STATUS_NOT_STARTED = 1;
const MATCH_STATUS_LIVE = 3;
const MATCH_STATUS_PREMATCH = 12;

// FIFA API Event Types
const EVENT_GOAL = 0;
const EVENT_YELLOW_CARD = 2;
const EVENT_SECOND_YELLOW_CARD_RED = 3; // Maybe?
const EVENT_STRAIGHT_RED = 4; // Maybe?
const EVENT_OWN_GOAL = 34;
const EVENT_PENALTY_GOAL = 41;
const EVENT_MISSED_PENALTY = 65;
const EVENT_PENALTY_FOUL = 72;

/**
 * Below this line, you should modify at your own risk
 */

/*
 * Get data from URL
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

/*
 * Post text and attachments to Slack
 */
function postToSlack($text, $attachments_text = '')
{
    var_dump($text);

    /* TODO Uncomment this when testing is finished

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

  var_dump(getUrl($slackUrl)); */
}

function getEventPlayerAlias($eventPlayerId)
{
    $response = json_decode(getUrl('https://api.fifa.com/api/v1/players/'.$eventPlayerId), true);
    return $response["Alias"][0]["Description"];
}

/*
 * ==================
 * SCRIPT STARTS HERE
 * ==================
 */

date_default_timezone_set("Zulu");
$dbFile = './worldCupDB.json';
$db = json_decode(file_get_contents($dbFile), true);

// Retrieve all matches
$response = json_decode(getUrl(
    'https://api.fifa.com/api/v1/calendar/matches?idCompetition='.ID_COMPETITION.'&idSeason='.ID_SEASON.
    '&count=500&language='.LOCALE), true);
$matches = $response["Results"];

// Find live matches (status 3 = in progress) and update score
foreach ($matches as $match)
{
    if (($match['MatchStatus'] != MATCH_STATUS_NOT_STARTED) && !in_array($match["IdMatch"], $db['live_matches']))
    {
        // yay new match !
        $db['live_matches'][] = $match["IdMatch"];
        $db[$match["IdMatch"]] = array(
            'stage_id' => $match["IdStage"],
            'teams' => [$match["Home"]["IdTeam"] => $match["Home"]["TeamName"][0]["Description"],
                $match["Away"]["IdTeam"] => $match["Away"]["TeamName"][0]["Description"]],
            'last_update' => microtime()
        );

        // notify slack & save data
        postToSlack(':zap: '.$language[LOCALE][0].' '.$match["Home"]["TeamName"][0]["Description"].' / '.$match["Away"]["TeamName"][0]["Description"].' '.$language[LOCALE][1].'! ');
    }

    if (in_array($match["IdMatch"], $db['live_matches']))
    {
        // update score
        $db[$match["IdMatch"]]['score'] = $match["Home"]["TeamName"][0]["Description"].' '.$match["Home"]["Score"].' - '.$match["Away"]["Score"].' '.$match["Away"]["TeamName"][0]["Description"];
    }

    file_put_contents($dbFile, json_encode($db)); // Save immediately, to avoid loops
}

// Post update on live matches (events since last updated time)
foreach ($db['live_matches'] as $matchId)
{
    $lastUpdateSeconds = explode(" ", $db[$matchId]['last_update'])[1];

    // Retrieve match events
    $response = json_decode(getUrl('https://api.fifa.com/api/v1/timelines/'.ID_COMPETITION.'/'.ID_SEASON.'/'.
        $db[$matchId]['stage_id'].'/'.$matchId.'?language='.LOCALE), true);
    $events = $response["Event"];
    foreach ($events as $event)
    {
        $eventType = $event["Type"];
        $eventTimeSeconds = strtotime($event["Timestamp"]);
        if ($eventTimeSeconds > $lastUpdateSeconds)
        {
            $matchTime = $event["MatchMinute"];
            $eventTeams = $db[$matchId]['teams'];
            $eventTeam = $eventTeams[$event["IdTeam"]];
            unset($eventTeams[$event["IdTeam"]]);
            $eventOtherTeam = reset($eventTeams);
            $eventPlayerAlias = null;

            switch ($eventType) {
                case EVENT_GOAL:
                case EVENT_PENALTY_GOAL:
                case EVENT_OWN_GOAL:
                    $eventPlayerAlias = getEventPlayerAlias($event["IdPlayer"]);
                    postToSlack($matchTime.' :soccer: '.$language[LOCALE][6].' '.$eventTeam.'!!! ('.$eventPlayerAlias.
                        ') '.$db[$matchId]['score']);
                    break;
                case EVENT_YELLOW_CARD:
                    $eventPlayerAlias = getEventPlayerAlias($event["IdPlayer"]);
                    postToSlack($matchTime.' :collision: '. $language[LOCALE][2].' '.$eventTeam.' ('.$eventPlayerAlias.')');
                    break;
                case EVENT_SECOND_YELLOW_CARD_RED:
                case EVENT_STRAIGHT_RED:
                    $eventPlayerAlias = getEventPlayerAlias($event["IdPlayer"]);
                    postToSlack($matchTime.' :collision: '.$language[LOCALE][3].' '.$eventTeam.' ('.$eventPlayerAlias.')');
                    break;
                case EVENT_PENALTY_FOUL:
                    postToSlack($matchTime.' :collision: ' . $language[LOCALE][5].' ' .$eventOtherTeam.'!!!');
                    break;
                case EVENT_MISSED_PENALTY:
                    $eventPlayerAlias = getEventPlayerAlias($event["IdPlayer"]);
                    postToSlack($matchTime.' :no_good: ' . $language[LOCALE][7].' ' .$eventTeam.'!!! ('.
                        $eventPlayerAlias.') '.$db[$matchId]['score']);
                    break;
            }

            $db[$matchId]['last_update'] = microtime();
        }
    }
}

// Record state for next run
file_put_contents($dbFile, json_encode($db));

exit(0);

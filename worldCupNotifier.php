<?php

/**
 * WorldCup Bot for Slack.
 *
 * It uses the unofficial FIFA json API (the one used for their mobile app iOS/Android).
 * It will post a message :
 *   - when a match starts
 *   - for red/yellow card
 *   - for the half time and end time
 *   - for every penalty
 *   - and of course, for every goal
 *
 * You will need a token from Slack.
 * Jump at https://api.slack.com/custom-integrations/legacy-tokens and you will find your token.
 *
 * @author j0k <jeremy.benoist@gmail.com>
 * @license MIT
 */

/**
 * All the configuration are just below
 */

// Slack stuff
const SLACK_CHANNEL    = 'C04BNCVT049';

const USE_PROXY     = false;
const PROXY         = 'http://myproxy:3128';
// If a proxy authentification is needed, set PROXY_USERPWD to "user:password"
const PROXY_USERPWD = false;

// Set to the language for updates
const LOCALE = 'pt-PT'; // fr-FR, en-GB

$language = array(
    'fr-FR' => array(
        'Le match',
        'est sur le point de commencer ',
        'Carton jaune',
        'Carton rouge',
        'But contre son camp',
        'Pénalty',
        'BUUUUUT',
        'Pénalty manqué',
        'commence',
        'Mi-temps',
        'Fin de la 2e période',
        'a repris',
        'Mi-temps de la prolongation',
        'Fin de la prolongation',
        'Fin de la séance de tirs au but',
    ),
    'en-GB' => array(
        'The match between',
        'is about to start',
        'Yellow card',
        'Red card',
        'Own goal',
        'Penalty',
        'GOOOOAL',
        'Missed penalty',
        'has started',
        'HALF TIME',
        'FULL TIME',
        'has resumed',
        'END OF 1ST ET',
        'END OF 2ND ET',
        'END OF PENALTY SHOOTOUT',
    ),
    'pt-PT' => array(
        'O jogo entre',
        'está para começar',
        'Cartão amarelo',
        'Cartão vermelho',
        'Gol contra',
        'Pênalti',
        'GOOOOOL',
        'Pênalti perdido',
        'começou',
        'INTERVALO',
        'ACRESCIMOS',
        'recomeçou',
        'FIM DO 1º TEMPO',
        'FIM DO 2º TEMPO',
        'FIM DA COBRANÇA DE PÊNALTIS',
    )
);

/**
 * FIFA API
 */

// 2022 World Cup
const ID_COMPETITION = 17;
const ID_SEASON = 255711;

// Match Statuses
const MATCH_STATUS_FINISHED = 0;
const MATCH_STATUS_NOT_STARTED = 1;
const MATCH_STATUS_LIVE = 3;
const MATCH_STATUS_PREMATCH = 12; // Maybe?

// Event Types
const EVENT_GOAL = 0;
const EVENT_YELLOW_CARD = 2;
const EVENT_STRAIGHT_RED = 3;
const EVENT_SECOND_YELLOW_CARD_RED = 4; // Maybe?
const EVENT_PERIOD_START = 7;
const EVENT_PERIOD_END = 8;
const EVENT_END_OF_GAME = 26;
const EVENT_OWN_GOAL = 34;
const EVENT_FREE_KICK_GOAL = 39;
const EVENT_PENALTY_GOAL = 41;
const EVENT_PENALTY_SAVED = 60;
const EVENT_PENALTY_CROSSBAR = 46;
const EVENT_PENALTY_MISSED = 65;
const EVENT_FOUL_PENALTY = 72;

// Periods
const PERIOD_1ST_HALF = 3;
const PERIOD_2ND_HALF = 5;
const PERIOD_1ST_ET   = 7;
const PERIOD_2ND_ET   = 9;
const PERIOD_PENALTY  = 11;

/**
 * Below this line, you should modify at your own risk
 */
date_default_timezone_set("America/Sao_Paulo");
$dbFile = './worldCupDB.json';
$db = json_decode(file_get_contents($dbFile), true);

// clean etag once in a while
if (isset($db['etag']) && count($db['etag']) > 5) {
    $db['etag'] = [];
}

/*
 * Get data from URL
 */
function getUrl($url, $doNotUseEtag = false)
{
    global $db;
    global $dbFile;

    $ch = curl_init($url);
    $options = array(
        CURLOPT_HEADER => 1,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer xoxb-6173064323-4386306105428-RJN5TRW3fAGkOvBBWR79LqPY',]
    );

    if (!$doNotUseEtag && isset($db['etag']) && array_key_exists($url, $db['etag']))
    {
        $options[CURLOPT_HTTPHEADER] = [
            'If-None-Match: "'.$db['etag'][$url].'"',
            'Authorization: Bearer xoxb-6173064323-4386306105428-RJN5TRW3fAGkOvBBWR79LqPY',
        ];
    }

    if (USE_PROXY)
    {
        $options[CURLOPT_PROXY] = PROXY;
    }

    if (PROXY_USERPWD)
    {
        $options[CURLOPT_PROXYUSERPWD] = PROXY_USERPWD;
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);

    if ($response !== false)
    {
        $response = explode("\n\r", $response);

        // retrieve etag header
        preg_match('/ETag\: "([0-9]+)"/i', $response[0], $etagMatched);
        if (count($etagMatched) > 1) {
            $etag = $etagMatched[1];

            $db['etag'][$url] = $etag;

            // save new etag for that url
            file_put_contents($dbFile, json_encode($db));
        }

        $content = $response[1];

        curl_close($ch);

        if (strlen(trim($content)) === 0) {
            // echo "304 Not Modified\n";
            return false;
        }

        return $content;
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
    $slackUrl = 'https://slack.com/api/chat.postMessage?channel='.SLACK_CHANNEL.
    '&text='.urlencode($text);

    if ($attachments_text)
    {
        $slackUrl .= '&attachments='.urlencode('[{"text": "'.$attachments_text.'"}]');
    }

    var_dump(getUrl($slackUrl));
}

function getEventPlayerAlias($eventPlayerId)
{
    $response = json_decode(getUrl('https://api.fifa.com/api/v1/players/'.$eventPlayerId, true), true);
    return $response["Alias"][0]["Description"];
}

/**
 * ==================
 * SCRIPT STARTS HERE
 * ==================
 */

// Retrieve all matches
$response = json_decode(getUrl('https://api.fifa.com/api/v1/calendar/matches?idCompetition='.ID_COMPETITION.'&idSeason='.ID_SEASON.'&count=500&language='.LOCALE), true);
$matches = [];

// in case of not a 304
if (null !== $response)
{
    $matches = $response["Results"];
}

// Find live matches and update score
foreach ($matches as $match)
{
    if ($match['MatchStatus'] == MATCH_STATUS_LIVE && !in_array($match["IdMatch"], $db['live_matches']))
    {
        // yay new match !
        $db['live_matches'][] = $match["IdMatch"];
        $db[$match["IdMatch"]] = array(
            'stage_id' => $match["IdStage"],
            'teamsById' => [
                $match["Home"]["IdTeam"] => $match["Home"]["TeamName"][0]["Description"],
                $match["Away"]["IdTeam"] => $match["Away"]["TeamName"][0]["Description"]
            ],
            'teamsByHomeAway' => [
                "Home" => $match["Home"]["TeamName"][0]["Description"],
                "Away" => $match["Away"]["TeamName"][0]["Description"]
            ],
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

    // Save immediately, to avoid loops
    file_put_contents($dbFile, json_encode($db));
}

// Post update on live matches (events since last updated time)
foreach ($db['live_matches'] as $key => $matchId)
{
    $homeTeamName = $db[$matchId]['teamsByHomeAway']["Home"];
    $awayTeamName = $db[$matchId]['teamsByHomeAway']["Away"];
    $lastUpdateSeconds = explode(" ", $db[$matchId]['last_update'])[1];

    // Retrieve match events
    $response = json_decode(getUrl('https://api.fifa.com/api/v1/timelines/'.ID_COMPETITION.'/'.ID_SEASON.'/'.$db[$matchId]['stage_id'].'/'.$matchId.'?language='.LOCALE), true);

    // in case of 304
    if (null === $response)
    {
        continue;
    }

    $events = $response["Event"];
    foreach ($events as $event)
    {
        $eventType = $event["Type"];
        $period = $event["Period"];
        $eventTimeSeconds = strtotime($event["Timestamp"]);
        if ($eventTimeSeconds > $lastUpdateSeconds)
        {
            $matchTime = $event["MatchMinute"];

            $teamsById = $db[$matchId]['teamsById'];
            $eventTeam = $teamsById[$event["IdTeam"]];
            unset($teamsById[$event["IdTeam"]]);
            $eventOtherTeam = reset($teamsById);
            $eventPlayerAlias = null;

            $score = $homeTeamName.' '.$event["HomeGoals"].' - '.$event["AwayGoals"].' '.$awayTeamName;
            $subject = '';
            $details = '';
            $interestingEvent = true;

            switch ($eventType) {
                // Timekeeping
                case EVENT_PERIOD_START:
                    switch ($period) {
                        case PERIOD_1ST_HALF:
                            $subject = ':zap: '.$language[LOCALE][0].' '.$homeTeamName.' / '.$awayTeamName.' '.$language[LOCALE][8].'!';
                            break;
                        case PERIOD_2ND_HALF:
                        case PERIOD_1ST_ET:
                        case PERIOD_2ND_ET:
                        case PERIOD_PENALTY:
                            $subject = ':runner: '.$language[LOCALE][0].' '.$homeTeamName.' / '.$awayTeamName.' '.$language[LOCALE][11];
                            break;
                    }
                    break;
                case EVENT_PERIOD_END:
                    switch ($period) {
                        case PERIOD_1ST_HALF:
                            $subject = ':toilet: '.$language[LOCALE][9].' '.$score;
                            $details = $matchTime;
                            break;
                        case PERIOD_2ND_HALF:
                            $subject = ':stopwatch: '.$language[LOCALE][10].' '.$score;
                            $details = $matchTime;
                            break;
                        case PERIOD_1ST_ET:
                            $subject = ':toilet: '.$language[LOCALE][12].' '.$score;
                            $details = $matchTime;
                            break;
                        case PERIOD_2ND_ET:
                            $subject = ':stopwatch: '.$language[LOCALE][13].' '.$score;
                            $details = $matchTime;
                            break;
                        case PERIOD_PENALTY:
                            $subject = ':stopwatch: '.$language[LOCALE][14].' '.$score.' ('.$event["HomePenaltyGoals"].' - '.$event["AwayPenaltyGoals"].')';
                            $details = $matchTime;
                            break;
                    }
                    break;

                // Goals
                case EVENT_GOAL:
                case EVENT_FREE_KICK_GOAL:
                case EVENT_PENALTY_GOAL:
                    $eventPlayerAlias = getEventPlayerAlias($event["IdPlayer"]);
                    $subject = ':soccer: '.$language[LOCALE][6].' '.$eventTeam.'!!!';
                    $details = $eventPlayerAlias.' ('.$matchTime.') '.$score;

                    if ($period === PERIOD_PENALTY) {
                        $details .= ' ('.$event["HomePenaltyGoals"].' - '.$event["AwayPenaltyGoals"].')';
                    }
                    break;
                case EVENT_OWN_GOAL:
                    $eventPlayerAlias = getEventPlayerAlias($event["IdPlayer"]);
                    $subject = ':face_palm: '.$language[LOCALE][4].' '.$eventTeam.'!!!';
                    $details = $eventPlayerAlias.' ('.$matchTime.') '.$score;
                    break;

                // Cards
                case EVENT_YELLOW_CARD:
                    $eventPlayerAlias = getEventPlayerAlias($event["IdPlayer"]);
                    $subject = ':collision: '. $language[LOCALE][2].' '.$eventTeam;
                    $details = $eventPlayerAlias.' ('.$matchTime.')';
                    break;
                case EVENT_SECOND_YELLOW_CARD_RED:
                case EVENT_STRAIGHT_RED:
                $eventPlayerAlias = getEventPlayerAlias($event["IdPlayer"]);
                $subject = ':collision: '. $language[LOCALE][3].' '.$eventTeam;
                $details = $eventPlayerAlias.' ('.$matchTime.')';
                    break;

                // Penalties
                case EVENT_FOUL_PENALTY:
                    $subject = ':exclamation: '.$language[LOCALE][5].' ' .$eventOtherTeam.'!!!';
                    break;
                case EVENT_PENALTY_MISSED:
                case EVENT_PENALTY_SAVED:
                case EVENT_PENALTY_CROSSBAR:
                    $eventPlayerAlias = getEventPlayerAlias($event["IdPlayer"]);
                    $subject = ':no_good: '.$language[LOCALE][7].' '.$eventTeam.'!!!';
                    $details =  $eventPlayerAlias.' ('.$matchTime.')';

                    if ($period === PERIOD_PENALTY) {
                        $details .= ' ('.$event["HomePenaltyGoals"].' - '.$event["AwayPenaltyGoals"].')';
                    }
                    break;

                // end of live match
                case EVENT_END_OF_GAME:
                    unset($db['live_matches'][$key]);
                    unset($db[$matchId]);
                    $interestingEvent = false;
                    break;

                default:
                    $interestingEvent = false;
                    continue;
            }

            if ($interestingEvent) {
                postToSlack($subject, $details);
                $db[$matchId]['last_update'] = microtime();
            }
        }
    }
}

// Record state for next run
file_put_contents($dbFile, json_encode($db));

exit(0);

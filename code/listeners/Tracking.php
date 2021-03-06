<?php namespace Milkyway\SS\SendThis\Listeners;

/**
 * Milkyway Multimedia
 * Tracking.php
 *
 * @package milkyway-multimedia/ss-send-this
 * @author Mellisa Hankins <mell@milkywaymultimedia.com.au>
 */

use Milkyway\SS\SendThis\Contracts\Event;
use Milkyway\SS\SendThis\Controllers\Tracker;
use SendThis_Log as Log;
use SendThis_Link as Link;
use Cookie;
use SS_Datetime;
use Convert;

class Tracking
{

    public function up(Event $e, $messageId, $email, $params, $response, $log, $headers)
    {
        if (!$log) {
            return;
        }

        if (isset($headers->{'X-LinkData'}) && $headers->{'X-LinkData'}) {
            $data = $headers->{'X-LinkData'};

            if (is_array($data)) {
                $log->Link_Data = $data;
            } elseif (is_object($data)) {
                $log->Link_Data = json_decode(json_encode($data), true);
            } else {
                @parse_str($data, $linkData);

                if ($linkData && count($linkData)) {
                    $log->Link_Data = $linkData;
                }
            }

            unset($headers->{'X-LinkData'});
        }

        if (!$e->mailer()->config()->tracking) {
            return;
        }

        if (isset($headers->{'X-TrackLinks'}) && $headers->{'X-TrackLinks'}) {
            $log->Track_Links = true;
            unset($headers->{'X-TrackLinks'});
        }

        if (isset($headers->{'X-Links-AttachSlug'}) && $headers->{'X-Links-AttachSlug'}) {
            $linkData = isset($linkData) ? $linkData : isset($data) ? $data : [];

            if (!$log->ID || !$log->Slug) {
                $log->generateHash();
            }

            if ($headers->{'X-Links-AttachSlug'} === true || $headers->{'X-Links-AttachSlug'} == 1) {
                if (!isset($linkData['utm_term'])) {
                    $linkData['utm_term'] = $log->Slug;
                }
            } elseif (!isset($linkData[$headers->{'X-Links-AttachSlug'}])) {
                $linkData[$headers->{'X-Links-AttachSlug'}] = $log->Slug;
            }

            $log->Link_Data = $linkData;

            unset($headers->{'X-Links-AttachSlug'});
        }
    }

    public function sending(Event $e, $messageId = '', $email = '', $params = [], $response = [], $log = null)
    {
        if (!$e->mailer()->config()->tracking) {
            if ($log && $params['message']->ContentType == 'text/html') {
                $params['message']->Body = $this->removeTracker($log,
                    $this->trackLinks($log, $params['message']->Body));

                if ($params['message']->AltBody) {
                    $params['message']->AltBody = $this->removeTracker($log, $params['message']->Body);
                }
            }

            return;
        }

        if ($log && isset($params['message'])) {
            if ($params['message']->ContentType == 'text/plain') {
                $params['message']->Body = $this->removeTracker($log, $params['message']->Body);
            } else {
                $params['message']->Body = $this->insertTracker(
                    $log,
                    $this->trackLinks($log, $params['message']->Body)
                );
            }

            if ($params['message']->AltBody) {
                $params['message']->AltBody = $this->removeTracker($log, $params['message']->Body);
            }
        }
    }

    public function opened(Event $e, $messageId = '', $email = '', $params = [], $response = [], $log = null)
    {
        if (!$e->mailer()->config()->tracking) {
            return;
        }
        $logs = [];

        if ($log) {
            $logs[] = $log;
        } elseif ($messageId) {
            $logs = Log::get()->filter('MessageID', $messageId)->sort('Created', 'ASC');
        }

        if (!count($logs)) {
            return;
        }

        foreach ($logs as $log) {
            if (!$log->Opened) {
                $log->Tracker = $this->getTrackerData($params);
                $log->Track_Client = $this->getClientFromTracker($log->Tracker);
                $log->Opened = SS_Datetime::now()->Rfc2822();
            }

            $log->Opens++;
            $log->write();
        }
    }

    public function clicked(Event $e, $messageId = '', $email = '', $params = [], $response = [], $link = null)
    {
        if (!$e->mailer()->config()->tracking || !$link) {
            return;
        }

        if (!Cookie::get('tracking-email-link-' . $link->Slug)) {
            $link->Visits++;
            Cookie::set('tracking-email-link-' . $link->Slug, true);
        }

        if (!$link->Clicked) {
            $link->Clicked = SS_Datetime::now()->Rfc2822();
        }

        $link->Clicks++;
        $link->write();
    }

    protected function insertTracker($log, $content)
    {
        $url = singleton('director')->absoluteURL(str_replace('$Slug', urlencode($log->Slug), Tracker::config()->slug));

        if (stripos($content, '</body')) {
            return preg_replace("/(<\/body[^>]*>)/i", '<img src="' . $url . '" alt="" />\\1', $content);
        } else {
            return $content . '<img src="' . $url . '" alt="" />';
        }
    }

    protected function removeTracker($log, $content)
    {
        $url = singleton('director')->absoluteURL(str_replace('$Slug', urlencode($log->Slug), Tracker::config()->slug));

        return str_replace(array_merge(['<img src="' . $url . '" alt="" />', $url]), '', $content);
    }

    protected function trackLinks($log, $content)
    {
        if (!$log->Track_Links || !count($log->LinkData)) {
            return $content;
        }

        if (preg_match_all("/<a\s[^>]*href=[\"|']([^\"]*)[\"|'][^>]*>(.*)<\/a>/siU", $content, $matches)) {
            if (isset($matches[1]) && ($urls = $matches[1])) {
                $id = (int)$log->ID;

                $replacements = [];

                array_unique($urls);

                $sorted = array_combine($urls, array_map('strlen', $urls));
                arsort($sorted);

                foreach ($sorted as $url => $length) {
                    if ($log->Track_Links) {
                        $link = $log->Links()->filter('Original', Convert::raw2sql($url))->first();

                        if (!$link) {
                            $link = Link::create();
                            $link->Original = $this->getURLWithData($log, $url);
                            $link->LogID = $id;
                            $link->write();
                        }

                        $replacements['"' . $url . '"'] = $link->URL;
                        $replacements["'$url'"] = $link->URL;
                    } else {
                        $replacements['"' . $url . '"'] = $this->getURLWithData($log, $url);
                        $replacements["'$url'"] = $this->getURLWithData($log, $url);
                    }
                }

                $content = str_ireplace(array_keys($replacements), array_values($replacements), $content);
            }
        }

        return $content;
    }

    protected function getURLWithData($log, $url)
    {
        if (!count($log->LinkData)) {
            return $url;
        }

        return singleton('mwm')->add_link_data($url, $log->LinkData);
    }

    public function getTrackerData($data)
    {
        $tracked = $data;

        if (!isset($tracked['Referrer'])) {
            $tracked['Referrer'] = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null;
        }

        if (isset($tracked['UserAgentString']) || isset($_SERVER['HTTP_USER_AGENT'])) {
            if (!isset($tracked['UserAgentString'])) {
                $tracked['UserAgentString'] = $_SERVER['HTTP_USER_AGENT'];
            }

            $agent = base64_encode($tracked['UserAgentString']);
            $response = @file_get_contents("http://www.useragentstring.com/?uas={$agent}&getJSON=all");

            if ($response) {
                $response = json_decode($response, true);

                if(!empty($response['agent_type'])) {
                    $tracked['Type'] = $response['agent_type'];
                }

                if(!empty($response['agent_name'])) {
                    $tracked['Client'] = $response['agent_name'];
                }

                if(!empty($response['agent_version'])) {
                    $tracked['ClientVersion'] = $response['agent_version'];
                }

                if(!empty($response['os_type'])) {
                    $tracked['OperatingSystemBrand'] = $response['os_type'];
                }

                if(!empty($response['os_name'])) {
                    $tracked['OperatingSystem'] = $response['os_name'];
                }

                if(!empty($response['os_versionName'])) {
                    $tracked['OperatingSystemVersion'] = $response['os_versionName'];

                    if(!empty($response['os_versionNumber'])) {
                        $tracked['OperatingSystemVersion'] .= ' ' . $response['os_versionNumber'];
                    }
                }
            }
        }

        if (isset($data['ip'])) {
            $geo = @file_get_contents("http://www.geoplugin.net/json.gp?ip=" . $data['ip']);

            if (($geo = json_decode($geo)) && $country = $geo->geoplugin_countryName) {
                $tracked['Country'] = $country;
            }
        }

        return $tracked;
    }

    protected function getClientFromTracker($tracked)
    {
        $client = '';

        if (strtolower($tracked['Type']) == 'email client') {
            $this->$client = $tracked['Client'];
        } elseif (strtolower($tracked['Type']) == 'browser' || strtolower($tracked['Type']) == 'mobile browser') {
            if (!preg_match('/.*[0-9]$/', $tracked['ClientFull'])) {
                $client = _t(
                    'SendThis_Log.EMAIL_CLIENT-MAC',
                    'Mac Client (Apple Mail or Microsoft Entourage)'
                );
            } elseif (isset($tracked['Referrer'])) {
                foreach (Log::config()->web_based_clients as $name => $url) {
                    if (preg_match("/$url/", $tracked['Referrer'])) {
                        $client = _t(
                            'SendThis_Log.WEB_CLIENT-' . strtoupper(str_replace(' ', '_', $name)),
                            $name
                        );
                        break;
                    }
                }
            }

            if (!$client) {
                $client = _t('SendThis_Log.BROWSER_BASED', 'Web Browser');
            }
        }

        return $client;
    }
}

<?php
/***********************************************************************************
 * The contents of this file are subject to the Extension License Agreement
 * ("Agreement") which can be viewed at
 * https://www.espocrm.com/extension-license-agreement/.
 * By copying, installing downloading, or using this file, You have unconditionally
 * agreed to the terms and conditions of the Agreement, and You may not use this
 * file except in compliance with the Agreement. Under the terms of the Agreement,
 * You shall not license, sublicense, sell, resell, rent, lease, lend, distribute,
 * redistribute, market, publish, commercialize, or otherwise transfer rights or
 * usage to the software or any modified version or derivative work of the software
 * created by or for you.
 *
 * Copyright (C) 2015-2025 EspoCRM, Inc.
 *
 * License ID: 99e925c7f52e4853679eb7c383162336
 ************************************************************************************/

namespace Espo\Modules\Google\Core\Google\Clients;

use Espo\Modules\Google\Core\Google\Actions\Event;
use Exception;

class Calendar extends Google
{
    protected $baseUrl = 'https://www.googleapis.com/calendar/v3/';

    protected function getPingUrl()
    {
        return $this->buildUrl('users/me/calendarList');
    }

    public function getCalendarList($params = [])
    {
        $method = 'GET';

        $url = $this->buildUrl('users/me/calendarList');

        $defaultParams = [
            'maxResults' => 50,
            'minAccessRole' => 'owner',
        ];

        $params = array_merge($defaultParams, $params);

        return $this->request($url, $params, $method);
    }

    public function getCalendarInfo($calendarId)
    {
        $method = 'GET';
        $url = $this->buildUrl('calendars/' . $calendarId);

        try {
            return $this->request($url, null, $method);
        }
        catch (Exception $e) {
            $GLOBALS['log']->error('GoogleCalendarERROR: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @param string $calendarId
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getEventList($calendarId, $params = [])
    {
        $method = 'GET';

        $url = $this->buildUrl("calendars/$calendarId/events");

        $defaultParams = [
            'maxResults' => 10,
            'alwaysIncludeEmail' => 'true',
        ];

        $params = array_merge($defaultParams, $params);

        try {
            return $this->request($url, $params, $method);
        } catch (Exception $e) {
            $result = [
                'success' => false
            ];

            if ($e->getCode() == 400 || $e->getCode() == 410) {
                $result['action'] = 'resetToken';
            }

            $GLOBALS['log']->error('GoogleCalendarERROR: ' . $e->getMessage());
            $paramsStr = print_r($params, true);
            $GLOBALS['log']->debug('GoogleCalendarERROR: Params: ' . $paramsStr);

            return $result;
        }
    }

    public function getEventInstances($calendarId, $eventId, $params = [])
    {
        $method = 'GET';

        $url = $this->buildUrl('calendars/' . $calendarId . '/events/' . $eventId .'/instances');

        $defaultParams = [
            'maxResults' => 10,
            'alwaysIncludeEmail' => 'true',
        ];

        $params = array_merge($defaultParams, $params);

        try {
            return $this->request($url, $params, $method);
        }
        catch (Exception $e) {
            $result = [
                'success' => false
            ];

            if ($e->getCode() == 400 || $e->getCode() == 410) {
                $result['action'] = 'resetToken';
            }
            else if ($e->getCode() == 403 || $e->getCode() == 404) {
                $result['action'] = 'deleteEvent';
            }

            $GLOBALS['log']->error('GoogleCalendarERROR: ' . $e->getMessage());
            $paramsStr = print_r($params, true);

            $GLOBALS['log']->debug('GoogleCalendarERROR: Params: ' . $paramsStr);

            return $result;
        }
    }

    public function deleteEvent($calendarId, $eventId)
    {
        $method = 'DELETE';
        $url = $this->buildUrl('calendars/' . $calendarId . '/events/' . $eventId);

        try {
            $this->request($url, null, $method);
        }
        catch (Exception $e) {
            $GLOBALS['log']->error("GoogleCalendarERROR:" . $e->getMessage());

            return false;
        }

        return true;
    }

    public function retrieveEvent($calendarId, $eventId)
    {
        $method = 'GET';
        $url = $this->buildUrl('calendars/' . $calendarId . '/events/' . $eventId);

        try {
            return $this->request($url, [], $method);
        } catch (Exception $e) {
            $GLOBALS['log']->error("GoogleCalendarERROR:" . $e->getMessage());

            return false;
        }
    }

    /**
     * @param string $calendarId
     * @param array<string, mixed> $event
     * @return false|array<string, mixed>
     */
    public function insertEvent($calendarId, $event)
    {
        $method = 'POST';

        $url = $this->buildUrl("calendars/$calendarId/events");

        try {
            return $this->request($url, json_encode($event), $method, 'application/json');
        } catch (Exception $e) {
            $GLOBALS['log']->error('GoogleCalendarERROR: ' . $e->getMessage());
            $paramsStr = print_r($event, true);
            $GLOBALS['log']->debug('GoogleCalendarERROR: Params: ' . $paramsStr);

            return false;
        }
    }

    /**
     * @param string $calendarId
     * @param string $eventId
     * @param array<string, mixed> $modification
     * @return false|array<string, mixed>
     */
    public function updateEvent($calendarId, $eventId, $modification)
    {
        $method = 'PUT';
        $url = $this->buildUrl('calendars/' . $calendarId . '/events/' . $eventId);

        try {
            return $this->request($url, json_encode($modification), $method, 'application/json');
        } catch (Exception $e) {
            $GLOBALS['log']->error('GoogleCalendarERROR: ' . $e->getMessage());
            $paramsStr = print_r($modification, true);
            $GLOBALS['log']->debug('GoogleCalendarERROR: Params: ' . $paramsStr);

            return false;
        }
    }
}

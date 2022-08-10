<?php
/* Copyright (C) 2022 Jonathan Vaughn <biosehnsucht@gmail.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>. */

/**
 * Freshstatus Transport
 *
 * @author biosehnsucht <biosehnsucht@gmail.com>
 * @copyright 2022 Jonathan Vaughn, LibreNMS
 * @license GPL
 */

namespace LibreNMS\Alert\Transport;

use LibreNMS\Alert\Transport;
use Illuminate\Support\Facades\Log;

class Freshstatus extends Transport
{
	const FRESHSTATUS_OPERATIONAL = 'OP';
	const FRESHSTATUS_OP = 'Operational';
	const FRESHSTATUS_PERFORMANCE_DEGRADED = 'PD';
	const FRESHSTATUS_PD = 'Performance Degraded';
	const FRESHSTATUS_PARTIAL_OUTAGE = 'PO';
	const FRESHSTATUS_PO = 'Partial Outage';
	const FRESHSTATUS_MAJOR_OUTAGE = 'MO';
	const FRESHSTATUS_MO = 'Major Outage';
	const FRESHSTATUS_UNDER_MAINTENANCE = 'UM';
	const FRESHSTATUS_UM = 'Under Maintenance';
	const FRESHSTATUS_RESOLVED = 'CL';
	const FRESHSTATUS_CL = 'Resolved';

	private static function prettyStatus($status)
	{
		switch ($status)
		{
			case self::FRESHSTATUS_OPERATIONAL: 			return self::FRESHSTATUS_OP; break;
			case self::FRESHSTATUS_PERFORMANCE_DEGRADED: 	return self::FRESHSTATUS_PD; break;
			case self::FRESHSTATUS_PARTIAL_OUTAGE: 			return self::FRESHSTATUS_PO; break;
			case self::FRESHSTATUS_MAJOR_OUTAGE: 			return self::FRESHSTATUS_MO; break;
			case self::FRESHSTATUS_UNDER_MAINTENANCE: 		return self::FRESHSTATUS_UM; break;
			case self::FRESHSTATUS_OP:	return self::FRESHSTATUS_OPERATIONAL; break;
			case self::FRESHSTATUS_PD:	return self::FRESHSTATUS_PERFORMANCE_DEGRADED; break;
			case self::FRESHSTATUS_PO:	return self::FRESHSTATUS_PARTIAL_OUTAGE; break;
			case self::FRESHSTATUS_MO:	return self::FRESHSTATUS_MAJOR_OUTAGE; break;
			case self::FRESHSTATUS_UM:	return self::FRESHSTATUS_UNDER_MAINTENANCE; break;
		}
		return False;
	}

	private static function statusList()
	{
		
		$list = [
			self::FRESHSTATUS_OPERATIONAL, 
			self::FRESHSTATUS_PERFORMANCE_DEGRADED,
			self::FRESHSTATUS_PARTIAL_OUTAGE,
			self::FRESHSTATUS_MAJOR_OUTAGE,
			self::FRESHSTATUS_UNDER_MAINTENANCE,
			];
		return implode(',', $list);
	}

	private function genUid($obj)
	{
		$data = [
			'device_id' => $obj['devid_id'],
			'rule_id' => $obj['rule_id'],
			'id' => $obj['id'],
			];
		return sha1(json_encode($data));
	}

    public function deliverAlert($obj, $opts)
    {
		$obj['uid'] = $this->genUid($obj);
        return $this->contactFreshstatus($obj, $opts);
    }

    public function contactFreshstatus($obj, $opts)
    {
		$incidentId = $this->findIncident($obj['uid']);
		// If we're clearing a non-existant incident, nothing to do
		if ($obj['status'] == True && $incidentId == False) 
		{ 
			return True; 
		}
		if ($incidentId != False)
		{
			if ($obj['status'] == True)
			{
				return $this->resolveIncident($obj, $incidentId);
			}
			else
			{
				return $this->updateIncident($obj, $incidentId);
			}
		}
		else
		{
			$componentId = $this->findServiceByName([$obj['hostname'], $obj['sysName']]);
			if ($componentId == False)
			{
				return False;
			}
			return $this->createIncident($obj, $componentId);
		}

		return true;
	}

	private function apiCall($path = '', $action = 'GET', $opts = []) 
	{
		$url = 'https://public-api.freshstatus.io/api/v1/' . $path;
		$headers = [
			'Accept: application/json',
			'Content-Type: application/json',
			];

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_USERPWD, $this->config['api-key'] . ':' . $this->config['api-subdomain']);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		if (in_array($action, ['POST', 'PATCH', 'DELETE']))
		{
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $action);
		}
		if (in_array($action, ['POST', 'PATCH']) && isset($opts['data']))
		{
			$json_data = json_encode($opts['data']);
			Log::error('json data: ' . $json_data);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $json_data);
		}
        $ret = curl_exec($curl);
		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		if (in_array($code, [200, 201]))
		{
			return json_decode($ret, true);
		}
		Log::error('apiCall got non-200: ' . $code);
		Log::error(var_export($ret, true));
		return False;
    }

	private function findServiceByName($needles = [])
	{
		$groupId = False;
		if (isset($this->config['group-scope']) && is_numeric($this->config['group-scope']))
		{
			$groupId = $this->config['group-scope'];
		}
		$services = $this->apiCall('services/');
		$results = $services['results'];
		foreach ($results as $result)
		{
			if (in_array($result['name'], $needles))
			{
				$match = False;
				if ($groupId === False) { $match = True; }
				else
				{
					if ($groupId == $result['group']['id']) { $match = True; }
				}
				if ($match) { return $result['id']; }
			}
		}
		return False;
	}

	private function statusStr($status)
	{
		if ($status == 'ok') { return self::FRESHSTATUS_OP; }
		if ($status == 'warning') { return $this->config['status-warning']; }
		if ($status == 'critical') { return $this->config['status-critical']; }
		return False;
	}

	private function uidStr($uid)
	{
		return 'LibreNMS-UID: ' . $uid;
	}

	private function findIncident($uid)
	{
		$incidents = $this->apiCall('incidents/');
		$results = $incidents['results'];
		foreach ($results as $result)
		{
			if (strpos($result['description'], $this->uidStr($uid)) !== False)
			{
				return $result['id'];
			}
		}
		return False;
	}

	private function createIncident($obj, $serviceId)
	{
		$data = [];
		$data['title'] = $obj['title'];
		$data['description'] = $obj['msg'] . "\n" . $this->uidStr($obj['uid']);
		$data['start_time'] = gmdate("Y-m-d\TH:i:s\Z", strtotime($obj['timestamp'])); 
		$data['is_private'] = false;
		$data['affected_components'] = [[
			'component' => (string)$serviceId, 
			'new_status' => $this->statusStr($obj['severity'])
			]];
		$data['source'] = null;
		$data['notification_options'] = [
			'send_notification' => False,
			'send_tweet' => False,
			];
		$opts = ['data' => $data];
		$incident = $this->apiCall('incidents/', 'POST', $opts);
		return True;
	}

	private function getIncident($incidentId)
	{
		$incident = $this->apiCall('incidents/' . $incidentId . '/');
		return $incident;
	}

	private function updateIncident($obj, $incidentId)
	{
		$incident = $this->getIncident($incidentId);
		$data = [];
		// Copy (most of) the existing incident data
		$data['title'] = $incident['title'];
		$data['description'] = $incident['description'];
		$data['start_time'] = $incident['start_time'];
		$data['end_time'] = $incident['end_time'];
		$data['is_private'] = $incident['is_private'];
		$data['affected_components'] = [];
		$data['source'] = $incident['source'];
		$data['notification_options'] = $incident['notification_options'];
		// If recovering alert, this will force an update
		// Otherwise, only update if a component's state doesn't match the alert state
		$updateNeeded = $obj['status'];
		foreach ($incident['affected_components'] as $component)
		{
			$newStatus = $this->statusStr($obj['severity']);
			if ($component['new_status'] != $newStatus)
			{
				$updateNeeded = True;
			}
			$data['affected_components'][] = [
				'component' => $component['component'],
				'new_status' => $newStatus,
				];
		}
		// If it turns out no changes occurred, then we don't actually do anything
		if ($updateNeeded == False) { return True; }
		$opts = ['data' => $data];
		$update = $this->apiCall('incidents/' . $incidentId . '/', 'PATCH', $opts);
		return True;
	}

	private function resolveIncident($obj, $incidentId)
	{
		$opts = [
			'data' => [
				'message' => 'Resolved automatically by LibreNMS.',
				],
			];
		return $this->apiCall('incidents/' . $incidentId . '/resolve/', 'POST', $opts);
	}

    public static function configTemplate()
    {
        return [
            'config' => [
                [
                    'title' => 'Subdomain',
					'name' => 'api-subdomain',
					'descr' => 'Freshstatus Subdomain',
					'type' => 'text',
                ],
				[
					'title' => 'API Key',
					'name' => 'api-key',
					'descr' => 'Freshstatus API Key',
					'type' => 'text',
				],
				[
					'title' => 'Group Scope',
					'name' => 'group-scope',
					'descr' => 'Limit scope for host matching to specified group ID (optional)',
					'type' => 'text',
				],
				[
					'title' => 'Warning Status',
					'name' => 'status-warning',
					'descr' => 'Freshstatus status to use for alerts with severity "warning"',
					'type' => 'select',
					'options' => [
						self::FRESHSTATUS_PD => self::FRESHSTATUS_PERFORMANCE_DEGRADED,
						self::FRESHSTATUS_PO => self::FRESHSTATUS_PARTIAL_OUTAGE,
						self::FRESHSTATUS_MO => self::FRESHSTATUS_MAJOR_OUTAGE,
						],
					'default' => self::FRESHSTATUS_PERFORMANCE_DEGRADED,
				],
				[
					'title' => 'Critical Status',
					'name' => 'status-critical',
					'descr' => 'Freshstatus status to use for alerts with severity "critical"',
					'type' => 'select',
					'options' => [
				   		self::FRESHSTATUS_PD => self::FRESHSTATUS_PERFORMANCE_DEGRADED,
                        self::FRESHSTATUS_PO => self::FRESHSTATUS_PARTIAL_OUTAGE,
                        self::FRESHSTATUS_MO => self::FRESHSTATUS_MAJOR_OUTAGE,
                        ],
					'default' => self::FRESHSTATUS_MAJOR_OUTAGE, // Got this from Sensu.php but doesn't appear to work?
				],
            ],
			'validation' =>
				[
					'api-subdomain' => 'required|string',
					'api-key' => 'required|string',
					'status-warning' => 'required|in:' . self::statusList(),
					'status-critical' => 'required|in:' . self::statusList(),
				]
        ];
    }
}

<?php
/**
 * @author Jannik Stehle <jstehle@owncloud.com>
 * @author Jan Ackermann <jackermann@owncloud.com>
 *
 * @copyright Copyright (c) 2021, ownCloud GmbH
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\Files_Primary_S3\Panels;

use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Template;

class Admin implements ISettings {
	public function __construct(private readonly IConfig $config) {
	}

	#[\Override]
	public function getPriority() {
		return 0;
	}

	#[\Override]
	public function getSectionID() {
		return 'encryption';
	}

	#[\Override]
	public function getPanel() {
		$objectstore = $this->config->getSystemValue('objectstore', null);
		if ($objectstore) {
			return new Template('files_primary_s3', 'settings');
		}

		return null;
	}
}

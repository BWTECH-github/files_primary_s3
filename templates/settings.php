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
script('files_primary_s3', 'settings');
style('files_primary_s3', 'settings');
/*
 * Modified by BW-Tech GmbH on 2026-09-17.
 * Changes:
 *   - only the hint itself, with its own id: the former wrapper
 *     div.section#encryptionAPI duplicated the id of the core card, and after
 *     the script moved the hint away it stayed behind as an empty card
 *
 * Das Skript hängt den Hinweis in die Kernkarte "Serverseitige Verschlüsselung"
 * direkt vor den Schalter, den er erklärt.
 */
?>
<p id="files-primary-s3-encryption-hint" class="warning" role="note">
	<?php p($l->t('Storage encryption is not compatible with S3 Object Storage.')); ?>
</p>

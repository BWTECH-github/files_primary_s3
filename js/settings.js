/**
 * Hinweis "Verschlüsselung und S3 als Primärspeicher" in der Kernkarte.
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-09-17.
 * Changes:
 *   - move only this app's hint (by id); before, every .warning of the page
 *     was moved in front of the switch, including warnings of other panels
 *   - no longer hides the default encryption module: the module list only
 *     appears once encryption is on, hiding it there explained nothing
 *   - the empty panel wrapper is removed instead of staying as an empty card
 *   - the disabled switch names the hint as its description
 */
$(document).ready(function () {
	var $hinweis = $('#files-primary-s3-encryption-hint');
	var $schalter = $('#encryptionAPI #enableEncryption');
	if (!$hinweis.length || !$schalter.length) {
		return;
	}

	var $rahmen = $hinweis.parent();
	$hinweis.insertBefore('#encryptionAPI #enable');
	if ($rahmen.children().length === 0) {
		$rahmen.remove();
	}

	// Ist die Verschlüsselung schon an, sperrt der Kern den Schalter selbst.
	// Sonst: nicht einschaltbar, und der Hinweis sagt, warum.
	if (!$schalter.prop('checked')) {
		$schalter.prop('disabled', true);
	}
	$schalter.attr('aria-describedby', 'files-primary-s3-encryption-hint');
});

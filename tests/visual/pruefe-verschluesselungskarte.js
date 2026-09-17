/**
 * Hinweis "Verschlüsselung und S3" in der Verwaltung, Bereich Verschlüsselung.
 *
 * Braucht eine Instanz mit 'objectstore' in config.php - ohne rendert das
 * Panel nichts. Geprüft wird, was die Sichtung vom 17.09.2026 belegt hat:
 *
 *   - keine zweite Karte mit der Kennung #encryptionAPI, keine leere Karte
 *   - genau ein Hinweis, in der Kernkarte direkt vor dem Schalter
 *   - fremde Warnungen der Seite bleiben, wo sie sind
 *   - Schalter gesperrt und mit dem Hinweis als Beschreibung
 *   - lesbar (Kontrast), deutsch, schmales Fenster ohne Querrollen,
 *     keine Konsolenfehler
 *
 * Aufruf: OC_URL=http://127.0.0.1:18140 OC_PASSWORD=... node tests/visual/pruefe-verschluesselungskarte.js
 * Playwright kommt aus dem Redesign-Repo, falls hier keins installiert ist.
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0
 */
'use strict';

let chromium;
try {
	({ chromium } = require('playwright'));
} catch (e) {
	({ chromium } = require('C:/git/owncloud.online-redesign/node_modules/playwright'));
}
const path = require('path');
const fs = require('fs');

const BASIS = process.env.OC_URL || 'http://127.0.0.1:18140';
const PASSWORT = process.env.OC_PASSWORD;
const BILDER = process.env.BILDER || path.join(__dirname, 'bilder');
if (!PASSWORT) {
	console.error('OC_PASSWORD fehlt.');
	process.exit(2);
}
fs.mkdirSync(BILDER, { recursive: true });

const ergebnisse = [];
function pruefe(name, ok, zusatz) {
	ergebnisse.push({ name, ok: ok === true, zusatz: zusatz === undefined ? '' : String(zusatz) });
}

function kontrast(a, b) {
	const lum = (farbe) => {
		const w = (farbe.match(/[\d.]+/g) || []).slice(0, 3).map((x) => {
			const c = parseFloat(x) / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
		});
		return 0.2126 * w[0] + 0.7152 * w[1] + 0.0722 * w[2];
	};
	const [h, d] = [lum(a), lum(b)].sort((x, y) => y - x);
	return Math.round(((h + 0.05) / (d + 0.05)) * 100) / 100;
}

async function messe(seite) {
	return seite.evaluate(() => {
		const hinweise = document.querySelectorAll('#files-primary-s3-encryption-hint');
		const hinweis = hinweise[0] || null;
		const karte = document.getElementById('encryptionAPI');
		const schalter = document.getElementById('enableEncryption');
		const enable = document.getElementById('enable');
		// Karten ohne Text: nach dem Umhängen des Hinweises blieb früher eine stehen.
		const leereKarten = Array.from(document.querySelectorAll('#app-content .section')).filter((k) => k.textContent.trim() === '');
		let hintergrund = 'rgb(255, 255, 255)';
		if (hinweis) {
			let el = hinweis;
			while (el) {
				const bg = getComputedStyle(el).backgroundColor;
				if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') {
					hintergrund = bg;
					break;
				}
				el = el.parentElement;
			}
		}
		return {
			encryptionApiAnzahl: document.querySelectorAll('[id="encryptionAPI"]').length,
			hinweisAnzahl: hinweise.length,
			hinweisInKarte: !!(hinweis && karte && karte.contains(hinweis)),
			hinweisVorSchalter: !!(hinweis && enable && hinweis.nextElementSibling === enable),
			hinweisText: hinweis ? hinweis.textContent.trim() : null,
			hinweisFarbe: hinweis ? getComputedStyle(hinweis).color : null,
			hinweisHintergrund: hintergrund,
			hinweisSichtbar: !!(hinweis && hinweis.getBoundingClientRect().height > 0),
			leereKarten: leereKarten.length,
			schalterGesperrt: schalter ? schalter.disabled : null,
			schalterBeschreibung: schalter ? schalter.getAttribute('aria-describedby') : null,
			querrollen: document.documentElement.scrollWidth > window.innerWidth + 1,
			fremdeWarnungenVorSchalter: enable ? Array.from(enable.parentElement.children).filter((k) => k.classList.contains('warning') && k.id !== 'files-primary-s3-encryption-hint' && k.compareDocumentPosition(enable) & Node.DOCUMENT_POSITION_FOLLOWING).length : null,
		};
	});
}

(async () => {
	const browser = await chromium.launch();
	const kontext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'de-DE' });
	const seite = await kontext.newPage();
	const konsole = [];
	seite.on('console', (m) => {
		if (m.type() === 'error') {
			konsole.push(m.text().slice(0, 160));
		}
	});

	await seite.goto(BASIS + '/index.php/login', { waitUntil: 'domcontentloaded' });
	await seite.fill('#user', process.env.OC_USER || 'admin');
	await seite.fill('#password', PASSWORT);
	await Promise.all([
		seite.waitForNavigation({ timeout: 60000 }).catch(() => {}),
		seite.click('#submit, button[type=submit], input[type=submit]'),
	]);
	// Nur lesen: nichts speichern.
	await kontext.route('**/*', (r) => (r.request().method() === 'GET' ? r.continue() : r.abort()));

	// Eine fremde Warnung in eine andere Karte legen, bevor die App-Skripte laufen:
	// sie darf nicht vor den Schalter wandern.
	await seite.addInitScript(() => {
		document.addEventListener('DOMContentLoaded', () => {
			const andere = document.querySelector('#app-content .section:not(#encryptionAPI)') || document.getElementById('app-content');
			if (andere) {
				const w = document.createElement('div');
				w.className = 'warning';
				w.id = 'probe-fremde-warnung';
				w.textContent = 'Fremde Warnung';
				andere.insertBefore(w, andere.firstChild);
			}
		}, { once: true, capture: true });
	});

	await seite.goto(BASIS + '/index.php/settings/admin?sectionid=encryption', { waitUntil: 'load' });
	await seite.waitForTimeout(1500);
	const m = await messe(seite);
	const fremd = await seite.evaluate(() => {
		const w = document.getElementById('probe-fremde-warnung');
		const karte = document.getElementById('encryptionAPI');
		return { vorhanden: !!w, inVerschluesselungskarte: !!(w && karte && karte.contains(w)) };
	});

	pruefe('genau eine Karte #encryptionAPI', m.encryptionApiAnzahl === 1, m.encryptionApiAnzahl);
	pruefe('genau ein Hinweis', m.hinweisAnzahl === 1, m.hinweisAnzahl);
	pruefe('Hinweis steht in der Kernkarte', m.hinweisInKarte);
	pruefe('Hinweis direkt vor dem Schalter', m.hinweisVorSchalter);
	pruefe('keine leere Karte', m.leereKarten === 0, m.leereKarten);
	pruefe('fremde Warnung bleibt in ihrer Karte', fremd.vorhanden && !fremd.inVerschluesselungskarte, JSON.stringify(fremd));
	pruefe('Schalter gesperrt', m.schalterGesperrt === true, m.schalterGesperrt);
	pruefe('Schalter nennt den Hinweis als Beschreibung', m.schalterBeschreibung === 'files-primary-s3-encryption-hint', m.schalterBeschreibung);
	pruefe('Hinweis deutsch', /Verschlüsselung/.test(m.hinweisText || '') && !/Storage encryption/.test(m.hinweisText || ''), m.hinweisText);
	const k = m.hinweisFarbe ? kontrast(m.hinweisFarbe, m.hinweisHintergrund) : 0;
	pruefe('Hinweis mind. 4,5:1', k >= 4.5, k + ' (' + m.hinweisFarbe + ' auf ' + m.hinweisHintergrund + ')');
	await seite.screenshot({ path: path.join(BILDER, 's3-verschluesselung-1440.png'), fullPage: true });

	await seite.setViewportSize({ width: 400, height: 860 });
	await seite.waitForTimeout(800);
	const mobil = await messe(seite);
	pruefe('400 px: kein Querrollen', mobil.querrollen === false);
	pruefe('400 px: Hinweis sichtbar', mobil.hinweisSichtbar);
	await seite.screenshot({ path: path.join(BILDER, 's3-verschluesselung-400.png'), fullPage: true });

	pruefe('keine Konsolenfehler', konsole.length === 0, konsole.join(' | '));

	await browser.close();
	let fehler = 0;
	for (const e of ergebnisse) {
		console.log((e.ok ? 'OK    ' : 'FEHL  ') + e.name + (e.zusatz ? '  (' + e.zusatz + ')' : ''));
		if (!e.ok) {
			fehler++;
		}
	}
	console.log('\n' + (ergebnisse.length - fehler) + '/' + ergebnisse.length + ' bestanden');
	process.exit(fehler === 0 ? 0 : 1);
})().catch((e) => {
	console.error(e);
	process.exit(1);
});

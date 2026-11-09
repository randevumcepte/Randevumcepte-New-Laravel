#!/usr/bin/env node
/**
 * JS modul surum denetimi: ayni modul farkli ?v= ile import ediliyorsa tarayici onu IKI ayri modul olarak yukler
 * (ayri durum: modal yigini, oturum, onbellek ikiye bolunur; eski surum onbellekten gelebilir). Bu betik
 * public/js altindaki her import / export-from / dinamik import() ile resources/views altindaki <script src> ve
 * asset('js/...') baglantilarini tarar; her modul icin kullanilan surumleri toplar ve birden fazla surum (surumsuz
 * kullanim dahil) varsa listeler, cikis kodu 1 olur.
 *
 *   node scripts/js-surum-denetle.mjs            # proje kokunden
 *   node scripts/js-surum-denetle.mjs <kok>      # baska kok (test)
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, posix, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const kok = process.argv[2] || join(dirname(fileURLToPath(import.meta.url)), '..');
const PUBLIC = join(kok, 'public');

function* dosyalar(dizin, uzantilar) {
  let girdiler;
  try { girdiler = readdirSync(dizin); } catch { return; }
  for (const ad of girdiler) {
    const yol = join(dizin, ad);
    const st = statSync(yol);
    if (st.isDirectory()) { if (ad !== 'vendor' && ad !== 'node_modules') yield* dosyalar(yol, uzantilar); }
    else if (uzantilar.some((u) => ad.endsWith(u))) yield yol;
  }
}

/** Kaynaktaki modul belirtecleri: [ham belirtec, satir]. */
export function belirtecler(metin, jsMi) {
  const sonuc = [];
  const ekle = (rx, grup = 1) => {
    for (const m of metin.matchAll(rx)) sonuc.push([m[grup], metin.slice(0, m.index).split('\n').length]);
  };
  if (jsMi) {
    ekle(/\b(?:import|export)\s[^'"`;]*?\bfrom\s*['"]([^'"]+)['"]/g); // import x from '...' / export {..} from '...'
    ekle(/\bimport\s*['"]([^'"]+)['"]/g);                              // import '...' (yan etki)
    ekle(/\bimport\s*\(\s*['"]([^'"]+)['"]\s*\)/g);                    // import('...')
  } else {
    ekle(/<script\b[^>]*\bsrc\s*=\s*["']([^"'{}]+)["']/g);            // <script src="/js/x.js?v=1">
    ekle(/asset\(\s*['"](js\/[^'"]+)['"]\s*\)\s*\}\}(\?v=[^"'\s>]*)?/g, 0); // {{ asset('js/x.js') }}?v=2
  }
  return sonuc;
}

/** Belirteci public/ altindaki yola ve surume ayir; JS disi / dis adres null. */
export function coz(belirtec, kaynakDosya) {
  let ham = belirtec;
  const asset = ham.match(/asset\(\s*['"](js\/[^'"]+)['"]\s*\)\s*\}\}(\?v=[^"'\s>]*)?/);
  if (asset) ham = '/' + asset[1] + (asset[2] || '');
  if (/^(https?:)?\/\//.test(ham)) return null;
  const [yolKismi, sorgu = ''] = ham.split('?');
  if (!yolKismi.endsWith('.js')) return null;
  const surum = (new URLSearchParams(sorgu).get('v')) ?? '';
  let yol;
  if (yolKismi.startsWith('/')) yol = posix.normalize(yolKismi);
  else if (yolKismi.startsWith('.')) {
    const kaynakUrl = '/' + relative(PUBLIC, dirname(kaynakDosya)).split(sep).join('/');
    yol = posix.normalize(posix.join(kaynakUrl, yolKismi));
  } else return null; // cıplak paket adi
  return { yol, surum };
}

export function denetle() {
  const kullanim = new Map(); // yol -> Map(surum -> [konum])
  const tara = (dosya, jsMi) => {
    const metin = readFileSync(dosya, 'utf8');
    for (const [b, satir] of belirtecler(metin, jsMi)) {
      const c = coz(b, dosya);
      if (!c) continue;
      if (!kullanim.has(c.yol)) kullanim.set(c.yol, new Map());
      const s = kullanim.get(c.yol);
      if (!s.has(c.surum)) s.set(c.surum, []);
      s.get(c.surum).push(relative(kok, dosya) + ':' + satir);
    }
  };
  for (const d of dosyalar(join(PUBLIC, 'js'), ['.js'])) tara(d, true);
  for (const d of dosyalar(join(kok, 'resources', 'views'), ['.php', '.html'])) tara(d, false);

  const cakisan = [...kullanim].filter(([, s]) => s.size > 1);
  return { modulSayisi: kullanim.size, cakisan };
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const { modulSayisi, cakisan } = denetle();
  if (!cakisan.length) {
    console.log(`JS surum denetimi: ${modulSayisi} modul, hepsi tek surumle import ediliyor.`);
    process.exit(0);
  }
  console.error(`JS surum denetimi: ${cakisan.length} modul birden fazla surumle import ediliyor:`);
  for (const [yol, s] of cakisan) {
    console.error(`  ${yol}`);
    for (const [v, yerler] of s) console.error(`    ?v=${v || '(yok)'}  ${yerler.length} yer: ${yerler.slice(0, 3).join(', ')}${yerler.length > 3 ? ', ...' : ''}`);
  }
  process.exit(1);
}

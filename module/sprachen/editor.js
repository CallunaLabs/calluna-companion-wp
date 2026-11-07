/**
 * Calluna Index, Modul „sprachen": Seitenleiste „Sprache" im Block-Editor.
 * Ohne Build-Schritt (wp.* Globals). Daten: window.callunaSprachen.
 */
(function (wp) {
  if (!wp || !wp.plugins || !window.callunaSprachen) return;
  var el = wp.element.createElement;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var useSelect = wp.data.useSelect;
  var Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
  var C = wp.components;
  var cfg = window.callunaSprachen;

  function Sprache() {
    var info = useSelect(function (select) {
      var ed = select('core/editor');
      return { id: ed.getCurrentPostId(), typ: ed.getCurrentPostType(), status: ed.getEditedPostAttribute('status') };
    }, []);
    var basis = cfg.basen[info.typ];
    var _d = useState(null), daten = _d[0], setDaten = _d[1];
    var _b = useState(''), beschaeftigt = _b[0], setBeschaeftigt = _b[1];
    var _f = useState(''), fehler = _f[0], setFehler = _f[1];

    function laden() {
      if (!info.id || !basis) return;
      wp.apiFetch({ path: '/wp/v2/' + basis + '/' + info.id + '?context=edit&_fields=calluna_sprache' })
        .then(function (r) { setDaten(r.calluna_sprache || null); })
        .catch(function (e) { setFehler(e.message || String(e)); });
    }
    useEffect(laden, [info.id, basis]);

    if (!basis) return null;
    if (!daten) return el(Panel, { name: 'calluna-sprache', title: 'Sprache' }, fehler ? el('p', null, fehler) : el(C.Spinner));

    function spracheSetzen(lang) {
      setBeschaeftigt('lang'); setFehler('');
      wp.apiFetch({ path: '/wp/v2/' + basis + '/' + info.id, method: 'POST', data: { calluna_sprache: { lang: lang } } })
        .then(function () { laden(); })
        .catch(function (e) { setFehler(e.message || String(e)); })
        .finally(function () { setBeschaeftigt(''); });
    }

    function anlegen(lang) {
      setBeschaeftigt(lang); setFehler('');
      wp.apiFetch({ path: '/calluna/v1/sprachen/uebersetzung', method: 'POST', data: { quelle: info.id, lang: lang } })
        .then(function (r) { if (r && r.bearbeiten) window.open(r.bearbeiten, '_blank'); laden(); })
        .catch(function (e) { setFehler(e.message || String(e)); })
        .finally(function () { setBeschaeftigt(''); });
    }

    var u = daten.uebersetzungen || {};
    var zeilen = cfg.sprachen.filter(function (l) { return l !== daten.lang; }).map(function (lang) {
      var t = u[lang];
      if (t) {
        return el('li', { key: lang },
          el('strong', null, lang.toUpperCase() + ' '),
          el('a', { href: 'post.php?post=' + t.id + '&action=edit', target: '_blank', rel: 'noopener' }, t.titel || ('#' + t.id)),
          t.status !== 'publish' ? el('span', { style: { opacity: 0.6 } }, ' (' + t.status + ')') : null);
      }
      return el('li', { key: lang },
        el('strong', null, lang.toUpperCase() + ' '),
        el(C.Button, { variant: 'link', isBusy: beschaeftigt === lang, disabled: !!beschaeftigt || info.status === 'auto-draft', onClick: function () { anlegen(lang); } }, 'Übersetzung anlegen'));
    });

    return el(Panel, { name: 'calluna-sprache', title: 'Sprache: ' + daten.lang.toUpperCase() },
      el(C.SelectControl, {
        label: 'Sprache dieses Dokuments',
        value: daten.lang,
        disabled: !!beschaeftigt,
        options: cfg.sprachen.map(function (l) { return { label: l.toUpperCase(), value: l }; }),
        onChange: spracheSetzen,
        __nextHasNoMarginBottom: true
      }),
      el('p', { style: { marginTop: '12px', marginBottom: '4px' } }, 'Übersetzungen'),
      el('ul', { style: { margin: 0 } }, zeilen),
      info.status === 'auto-draft' ? el('p', { style: { opacity: 0.7 } }, 'Erst speichern, dann Übersetzungen anlegen.') : null,
      fehler ? el(C.Notice, { status: 'error', isDismissible: false }, fehler) : null
    );
  }

  wp.plugins.registerPlugin('calluna-sprachen', { render: Sprache, icon: 'translation' });
})(window.wp);

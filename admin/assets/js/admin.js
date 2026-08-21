/*!
 * AI Snippet SEO Pro — Admin Scripts
 * Version: 1.0.0
 * Handles editor UI (live preview, score meter), AI generation, and bulk polling.
 */
jQuery(document).ready(function ($) {

	/* =========================================================
	 * Constants
	 * ======================================================= */
	var BTN_LABEL = 'Generate with AI Snippet SEO Pro';
	var minTitle = 30, maxTitle = 60;
	var minDesc  = 120, maxDesc = 160;
	var maxSlug  = 75;

	function ignoreSpecial(str) {
		return (str || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ');
	}
	function hasKW(text, kw) {
		if (!kw) return false;
		var t = ignoreSpecial(text);
		var k = ignoreSpecial(kw);
		return t.indexOf(k.trim()) !== -1;
	}

	/* =========================================================
	 * Score calculation + color
	 * ======================================================= */
	function calcScore(fw, ti, de, slug) {
		var cond   = 0;
		var arr    = fw.split(',');
		var mainKW = arr[0] ? arr[0].trim().toLowerCase() : '';

		if (mainKW && hasKW(ti, mainKW))  cond++;
		if (mainKW && hasKW(de, mainKW))  cond++;

		var sLow  = ignoreSpecial(slug).replace(/\s+/g, '');
		var kwLow = ignoreSpecial(mainKW).replace(/\s+/g, '');
		if (mainKW && sLow.indexOf(kwLow) !== -1) cond++;

		if (ti.length >= minTitle && ti.length <= maxTitle) cond++;
		if (slug.length > 0 && slug.length <= maxSlug)      cond++;
		if (de.length >= minDesc && de.length <= maxDesc)   cond++;

		var sc = cond * 17;
		if (sc > 100) sc = 100;
		return sc;
	}
	function getColor(score) {
		if (score >= 100) return '#0091e0';
		else if (score >= 67) return '#ffab00';
		return '#dc3232';
	}

	/* =========================================================
	 * Small helper HTML fragments
	 * ======================================================= */
	var ok = '<span style="color:#0091e0;font-weight:bold;">✔</span>';
	var no = '<span style="color:red;font-weight:bold;">✘</span>';

	function boldFocus(str, kw) {
		if (!str || !kw) return str;
		var re = new RegExp('(' + kw.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&') + ')', 'gi'); // RegExp-escape
		return str.replace(re, '<strong>$1</strong>');
	}

	/* =========================================================
	 * DOM handles
	 * ======================================================= */
	var $fw        = $('#aissp_focus_keyword');
	var $ti        = $('#aissp_seo_title');
	var $de        = $('#aissp_seo_description');
	var $slug      = $('#aissp_preview_slug');
	var $score     = $('#aissp_score');
	var $sideScore = $('#aissp_side_score');
	var $sideTitle = $('#aissp_side_title');
	var $sideDesc  = $('#aissp_side_desc');
	var $prevTitle = $('#aissp_preview_title');
	var $prevDesc  = $('#aissp_preview_desc');
	var $checkBox  = $('#aissp_checklist_box');
	var $fkwCount  = $('#aissp_fkw_count');
	var $tiCount   = $('#aissp_title_count');
	var $deCount   = $('#aissp_desc_count');

	/* =========================================================
	 * Live preview + checklist updates
	 * ======================================================= */
	function updateAll() {
		var fw = $fw.val().trim();
		var ti = $ti.val().trim();
		var de = $de.val().trim();
		var sl = $slug.text().trim();

		if ($fkwCount.length) $fkwCount.text('(' + fw.length + ' chars)');
		if ($tiCount.length)  $tiCount.text('(' + ti.length + ' chars)');
		if ($deCount.length)  $deCount.text('(' + de.length + ' chars)');

		$prevTitle.text(ti || '(No Title)');
		$prevDesc.text(de || '(No Meta Description)');

		var sc  = calcScore(fw, ti, de, sl);
		var col = getColor(sc);

		$score
			.css({ background: col, padding: '6px 9px', fontSize: '12px', fontWeight: '600', color: '#fff', borderRadius: '3px' })
			.text(sc + ' / 100');

		if ($sideScore.length) {
			$sideScore
				.css({ background: col, padding: '6px 9px', fontSize: '12px', fontWeight: '600', color: '#fff', borderRadius: '3px' })
				.text(sc + ' / 100');
		}
		if ($sideTitle.length) {
			$sideTitle.html('<strong>Title:</strong> ' + (ti ? ti : '(No Title)'));
		}
		if ($sideDesc.length) {
			$sideDesc.html('<strong>Description:</strong> ' + (de ? de : '(No Meta Description)'));
		}

		if (fw) {
			var mainKW = fw.split(',')[0] ? fw.split(',')[0].trim() : '';
			$prevTitle.html(boldFocus($prevTitle.text(), mainKW));
			$prevDesc.html(boldFocus($prevDesc.text(), mainKW));
		}

		if ($checkBox.length) {
			var arr    = fw.split(',');
			var mainKW = arr[0] ? arr[0].trim().toLowerCase() : '';
			var slTest = sl.toLowerCase().replace(/\s+/g, '-');
			var kwTest = mainKW.replace(/\s+/g, '-');

			var c1 = mainKW && hasKW(ti, mainKW);
			var c2 = mainKW && hasKW(de, mainKW);
			var c3 = mainKW && slTest.indexOf(kwTest) !== -1;
			var c4 = (ti.length >= minTitle && ti.length <= maxTitle);
			var c5 = (sl.length > 0 && sl.length <= maxSlug);
			var c6 = (de.length >= minDesc && de.length <= maxDesc);

			function row(okVal, txt) {
				return '<p style="margin:0;">' + (okVal ? ok : no) + ' ' + txt + '</p>';
			}

			var html  = '<strong style="display:block;margin-bottom:4px;border-bottom:1px solid #ddd;">Snippet Checklist</strong>';
			html     += row(c1, 'Focus Keyword in Title');
			html     += row(c2, 'Focus Keyword in Description');
			html     += row(c3, 'Focus Keyword in URL Slug');
			html     += row(c4, 'Title 30–60 Characters');
			html     += row(c5, 'Slug ≤ 75 Characters');
			html     += row(c6, 'Description 120–160 Characters');

			$checkBox.html(html);
		}
	}

	$fw.on('input', updateAll);
	$ti.on('input', updateAll);
	$de.on('input', updateAll);
	updateAll();

	/* =========================================================
	 * Generate snippet via AI + token-reduce
	 * ======================================================= */
	$('#aissp-generate-ai').on('click', function (e) {
		e.preventDefault();
		var pid = $(this).data('post-id') || parseInt($('#post_ID').val(), 10);
		if (!pid || isNaN(pid)) {
			alert('AI Snippet SEO Pro: Post ID not found on this screen.');
			return;
		}

		var $btn = $(this);
		$('#aissp-ai-status').text('');
		$btn.text('Generating…').prop('disabled', true);

		$.ajax({
			url : AISSP_Ajax.ajaxurl,
			type: 'POST',
			data: {
				action    : 'aissp_generate_snippet',
				nonce     : AISSP_Ajax.nonce,
				post_id   : pid,
				plugin    : 'ai-snippet-seo-pro',
				slug      : $('#post_name').val() || '',
				auto_slug : ($('#aissp_auto_slug').length && $('#aissp_auto_slug').is(':checked')) ? 'yes' : 'no'
			},

			success: function (resp) {
				$btn.text(BTN_LABEL).prop('disabled', false);

				if (resp.success) {
					var d = resp.data;

					$('#aissp_focus_keyword').val(d.kw  || '');
					$('#aissp_seo_title')    .val(d.ti  || '');
					$('#aissp_seo_description').val(d.de || '');
					$('#aissp_preview_slug') .text(d.slug || '');

					updateAll();
					$('#aissp-ai-status').css('color', '#0091e0').text('Done!');

				} else {
					var raw = (resp.data && resp.data.msg) ? resp.data.msg : 'Unknown error';

					if (/No tokens left/i.test(raw)) {
						// Match the wording used in the bulk-notice flow
						alert('AI Snippet SEO Pro: Failed – no tokens left. Buy Tokens to continue.');
						if (confirm('Open Buy Tokens page in a new tab?')) {
							window.open('https://rankpilotai.com/tokens/?plugin=ai-snippet-seo-pro', '_blank');
						}
					} else if (/Not enough tokens for the selected model/i.test(raw)) {
						alert('AI Snippet SEO Pro: Not enough tokens for the selected model. Please choose a cheaper model (e.g. GPT-4 Turbo) or buy more tokens.');
					} else {
						alert('AI Snippet SEO Pro: ' + raw);
					}
				}
			},
			error: function (e) {
				$btn.text(BTN_LABEL).prop('disabled', false);
				alert('AI Snippet SEO Pro: Request failed. Check console.');
				console.error(e);
			}
		});
	});
});

(function($){
  // URL param helper
  function getParam(n){
    const m = new URLSearchParams(window.location.search);
    return m.get(n) || '';
  }
  // Update progress text in the bulk box
  function updBox(t){ $('#aissp-bulk-text').text(t); }

  const job = getParam('aissp_job');
  if(job && $('#aissp-bulk-box').length){
    let ticking = false;

    // Polling loop
    function tick(){
      if(ticking) return; ticking = true;
      $.post(AISSP_Ajax.ajaxurl, {
        action: 'aissp_bulk_tick',
        nonce: AISSP_Ajax.nonce,
        job: job
      }).done(function(res){
        if(!res || !res.success){ updBox('error'); return; }
        const d = res.data || {};
        if(d.done){
          // finished → redirect
          window.location = d.redirect || window.location.href.replace(/([?&])aissp_job=[^&]+&?/,'$1');
          return;
        }
        updBox(`processed ${d.i}/${d.total} — success ${d.s}, failed ${d.f}`);
        ticking = false;
        setTimeout(tick, 500); // 0.5s
      }).fail(function(){
        ticking = false;
        setTimeout(tick, 1000);
      });
    }

    // Cancel current job
    $('#aissp-bulk-cancel').on('click', function(e){
      e.preventDefault();
      $.post(AISSP_Ajax.ajaxurl, { action:'aissp_bulk_cancel', nonce:AISSP_Ajax.nonce, job:job })
        .always(function(){ updBox('cancelled'); });
    });

    updBox('starting');
    tick();
  }
})(jQuery);

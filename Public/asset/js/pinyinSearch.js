/*

后台搜索框拼音增强
Ver 1.0.0.0 20260714
Code by Codex

*/
(function(window, $) {
	'use strict';

	if (!$) {
		return;
	}

	var transferSearchInstalled = false;
	var rowSearchTextCache = typeof WeakMap === 'function' ? new WeakMap() : null;

	function getPinyinApi() {
		return window.pinyinPro && typeof window.pinyinPro.pinyin === 'function' ? window.pinyinPro : null;
	}

	function stripHtml(value) {
		if (value === null || value === undefined) {
			return '';
		}
		var text = String(value);
		if (text.indexOf('<') === -1) {
			return text;
		}
		var div = document.createElement('div');
		div.innerHTML = text;
		return div.textContent || div.innerText || '';
	}

	function normalize(value) {
		return stripHtml(value).toLowerCase().replace(/\s+/g, ' ').trim();
	}

	function compact(value) {
		return normalize(value).replace(/\s+/g, '');
	}

	function toPinyin(text, pattern, separator, nonZh) {
		var api = getPinyinApi();
		if (!api || !text) {
			return '';
		}
		try {
			return api.pinyin(text, {
				pattern: pattern || 'pinyin',
				toneType: 'none',
				type: 'string',
				separator: separator === undefined ? ' ' : separator,
				nonZh: nonZh || 'spaced'
			});
		} catch (e) {
			return '';
		}
	}

	function buildSearchText(value) {
		var raw = normalize(value);
		if (raw === '') {
			return '';
		}
		var fullPinyin = normalize(toPinyin(raw, 'pinyin', ' ', 'spaced'));
		var firstPinyin = normalize(toPinyin(raw, 'first', '', 'removed'));
		return [
			raw,
			compact(raw),
			fullPinyin,
			compact(fullPinyin),
			firstPinyin
		].join(' ');
	}

	function rowSearchText(row) {
		if (!row || typeof row !== 'object') {
			return buildSearchText(row);
		}
		if (rowSearchTextCache && rowSearchTextCache.has(row)) {
			return rowSearchTextCache.get(row);
		}
		var values = [];
		Object.keys(row).forEach(function(key) {
			if (key.charAt(0) === '_' || key.charAt(0) === '$') {
				return;
			}
			var value = row[key];
			if (value === null || value === undefined) {
				return;
			}
			if (typeof value === 'object') {
				return;
			}
			values.push(value);
		});
		var text = buildSearchText(values.join(' '));
		if (rowSearchTextCache) {
			rowSearchTextCache.set(row, text);
		}
		return text;
	}

	function matchesSearch(haystack, query) {
		var text = normalize(query);
		if (text === '') {
			return true;
		}
		var target = normalize(haystack);
		var compactTarget = compact(target);
		return text.split(/\s+/).every(function(term) {
			var compactTerm = compact(term);
			return target.indexOf(term) !== -1 || (compactTerm !== '' && compactTarget.indexOf(compactTerm) !== -1);
		});
	}

	function installBootstrapTableSearch() {
		if (!getPinyinApi() || !$.fn || !$.fn.bootstrapTable || !$.fn.bootstrapTable.defaults) {
			return;
		}
		if ($.fn.bootstrapTable.defaults.__ciPinyinSearchInstalled) {
			return;
		}
		$.extend($.fn.bootstrapTable.defaults, {
			customSearch: function(data, text) {
				if (!text) {
					return data;
				}
				return $.grep(data, function(row) {
					return matchesSearch(rowSearchText(row), text);
				});
			}
		});
		$.fn.bootstrapTable.defaults.__ciPinyinSearchInstalled = true;
	}

	function applyTransferSearch(input) {
		var $input = $(input);
		var $box = $input.closest('.layui-transfer-box');
		var $items = $box.find('.layui-transfer-data > li');
		if (!$items.length) {
			return;
		}
		var query = $input.val();
		var matchedCount = 0;
		$items.each(function() {
			var $item = $(this);
			var $checkbox = $item.find('input[type="checkbox"]');
			var searchText = $item.data('ciPinyinSearchText');
			if (!searchText) {
				searchText = buildSearchText($item.text() + ' ' + ($checkbox.attr('title') || ''));
				$item.data('ciPinyinSearchText', searchText);
			}
			var matched = matchesSearch(searchText, query);
			if (matched) {
				matchedCount++;
			}
			$item.toggleClass('layui-hide', !matched);
			$checkbox.data('hide', !matched);
		});
		$box.find('.layui-none').toggle(query !== '' && matchedCount === 0);
	}

	function installLayuiTransferSearch() {
		if (!getPinyinApi() || transferSearchInstalled) {
			return;
		}
		transferSearchInstalled = true;
		$(document).on('keyup input', '.layui-transfer-search input', function() {
			var input = this;
			window.setTimeout(function() {
				applyTransferSearch(input);
			}, 0);
		});
	}

	function install() {
		installBootstrapTableSearch();
		installLayuiTransferSearch();
	}

	install();
	$(install);

	window.CIPinyinSearch = {
		install: install,
		buildSearchText: buildSearchText,
		matchesSearch: matchesSearch
	};
})(window, window.jQuery);

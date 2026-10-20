/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
define(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';

    return function (config, element) {
        var output = $(element).next('.dmlab-typesafe-test-result');

        function show(type, lines) {
            output.removeClass('message-success message-warning message-error').addClass('message-' + type).empty()
                .append(lines.filter(Boolean).map(function (line) { return $('<div>').text(line); })).show();
        }

        $(element).on('click', function () {
            var button = this, body = new FormData();

            button.disabled = true;
            body.append('form_key', button.dataset.formKey);
            fetch(button.dataset.testUrl, {method: 'POST', body: body, headers: {'X-Requested-With': 'XMLHttpRequest'}})
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var lines = [data.message, data.model, data.note];

                    if (!data.success) {
                        show('error', [data.message]);
                    } else if (data.warning) {
                        show('warning', lines.concat(data.warning));
                    } else {
                        show('success', lines);
                    }
                })
                .catch(function () {
                    show('error', [$t('Connection test request failed.')]);
                })
                .finally(function () { button.disabled = false; });
        });
    };
});

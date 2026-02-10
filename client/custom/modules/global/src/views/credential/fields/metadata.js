define("global:views/credential/fields/metadata", ["views/fields/base"], function (
    Dep,
) {
    /**
     * Custom field view for Credential.metadata that provides a nice
     * JSON editor with syntax highlighting via Ace editor.
     *
     * In detail mode, displays formatted JSON.
     * In edit mode, provides an Ace JSON editor.
     */
    return Dep.extend({

        ace: null,
        editor: null,
        containerId: null,

        height: 150,
        maxLineDetailCount: 80,
        maxLineEditCount: 200,

        // language=Handlebars
        detailTemplateContent:
            '{{#if isNotEmpty}}' +
                '<div id="{{containerId}}">{{{value}}}</div>' +
            '{{else}}' +
                '{{#if isSet}}' +
                    '<span class="none-value">{{translate "None"}}</span>' +
                '{{else}}' +
                    '<span class="loading-value"></span>' +
                '{{/if}}' +
            '{{/if}}',

        // language=Handlebars
        editTemplateContent:
            '<div id="{{containerId}}">{{{value}}}</div>',

        // language=Handlebars
        listTemplateContent:
            '{{#if isNotEmpty}}<span class="text-muted">[JSON]</span>{{else}}<span class="none-value">{{translate "None"}}</span>{{/if}}',

        setup: function () {
            Dep.prototype.setup.call(this);

            this.height = this.options.height || this.params.height || this.height;
            this.containerId = 'metadata-editor-' + Math.floor((Math.random() * 10000) + 1).toString();

            if (this.mode === this.MODE_EDIT || this.mode === this.MODE_DETAIL) {
                this.wait(this.requireAce());
            }

            this.on('remove', function () {
                if (this.editor) {
                    this.editor.destroy();
                }
            }.bind(this));

            this.validations.push('json');
        },

        requireAce: function () {
            return Espo.loader.requirePromise('lib!ace')
                .then(function (lib) {
                    this.ace = lib;

                    var list = [
                        Espo.loader.requirePromise('lib!ace-ext-language_tools'),
                        Espo.loader.requirePromise('lib!ace-mode-json'),
                    ];

                    if (this.getThemeManager().getParam('isDark')) {
                        list.push(
                            Espo.loader.requirePromise('lib!ace-theme-tomorrow_night')
                        );
                    }

                    return Promise.all(list);
                }.bind(this));
        },

        data: function () {
            var data = Dep.prototype.data.call(this);
            var value = this.model.get(this.name);

            data.containerId = this.containerId;
            data.isNotEmpty = value != null;
            data.isSet = value !== undefined;

            try {
                if (value && typeof value === 'object') {
                    data.value = JSON.stringify(value, null, '  ');
                } else if (typeof value === 'string') {
                    // Try to prettify if it's a JSON string.
                    data.value = JSON.stringify(JSON.parse(value), null, '  ');
                } else {
                    data.value = value ? String(value) : null;
                }
            } catch (e) {
                data.value = value ? String(value) : null;
            }

            return data;
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            this.$editor = this.$el.find('#' + this.containerId);

            if (
                this.$editor.length &&
                this.ace &&
                (
                    this.mode === this.MODE_EDIT ||
                    this.mode === this.MODE_DETAIL ||
                    this.mode === this.MODE_LIST
                )
            ) {
                this.$editor.css('fontSize', 'var(--font-size-base)');

                if (this.mode === this.MODE_EDIT) {
                    this.$editor.css('minHeight', this.height + 'px');
                }

                var editor = this.editor = this.ace.edit(this.containerId);

                editor.setOptions({fontFamily: 'var(--font-family-monospace)'});
                editor.setFontSize('var(--font-size-base)');
                editor.container.style.lineHeight = 'var(--line-height-computed)';
                editor.renderer.updateFontSize();

                editor.setOptions({
                    maxLines: this.mode === this.MODE_EDIT ? this.maxLineEditCount : this.maxLineDetailCount,
                    enableLiveAutocompletion: true,
                });

                if (this.getThemeManager().getParam('isDark')) {
                    editor.setOptions({
                        theme: 'ace/theme/tomorrow_night',
                    });
                }

                if (this.isEditMode()) {
                    editor.getSession().on('change', function () {
                        this.trigger('change', {ui: true});
                    }.bind(this));

                    editor.getSession().setUseWrapMode(true);
                }

                if (this.isReadMode()) {
                    editor.setReadOnly(true);
                    editor.renderer.$cursorLayer.element.style.display = 'none';
                    editor.renderer.setShowGutter(false);
                }

                editor.setShowPrintMargin(false);
                editor.getSession().setUseWorker(false);
                editor.commands.removeCommand('find');
                editor.setHighlightActiveLine(false);

                var Mode = this.ace.require('ace/mode/json').Mode;
                editor.session.setMode(new Mode());
            }
        },

        /**
         * Validate that the content is valid JSON.
         * @returns {boolean} true if validation fails.
         */
        validateJson: function () {
            if (!this.editor) {
                return false;
            }

            var raw = this.editor.getValue();

            if (!raw || !raw.trim()) {
                return false;
            }

            try {
                JSON.parse(raw);
            } catch (e) {
                var message = this.translate('Not valid') + ' (JSON)';
                this.showValidationMessage(message, '.ace_editor');
                return true;
            }

            return false;
        },

        fetch: function () {
            var value = null;

            if (!this.editor) {
                var obj = {};
                obj[this.name] = null;
                return obj;
            }

            var raw = this.editor.getValue();

            if (!raw || !raw.trim()) {
                var obj = {};
                obj[this.name] = null;
                return obj;
            }

            try {
                value = JSON.parse(raw);
            } catch (e) {
                // Ignore parse errors; validation will catch them.
                value = null;
            }

            var obj = {};
            obj[this.name] = value;
            return obj;
        },
    });
});

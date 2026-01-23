/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * Color Picker Popover for Calendar
 *
 * Google Calendar-style color picker with predefined palette
 * and option for custom color using bootstrap-colorpicker.
 */

import View from 'view';

class ColorPickerPopover extends View {

    template = 'global:calendar/color-picker-popover'

    /**
     * Predefined color palette (Google Calendar style)
     * @type {string[]}
     */
    palette = [
        '#4285f4', // Blue
        '#0f9d58', // Green
        '#f4b400', // Yellow
        '#db4437', // Red
        '#ab47bc', // Purple
        '#00acc1', // Cyan
        '#ff7043', // Orange
        '#9e9e9e', // Gray
        '#5c6bc0', // Indigo
        '#26a69a', // Teal
        '#ec407a', // Pink
        '#8d6e63', // Brown
    ]

    /**
     * @type {string|null}
     */
    selectedColor = null

    /**
     * @type {string}
     */
    userId = null

    events = {
        /** @this ColorPickerPopover */
        'click .color-picker-color': function (e) {
            const color = $(e.currentTarget).data('color');
            this.selectColor(color);
        },
        /** @this ColorPickerPopover */
        'click [data-action="customColor"]': function (e) {
            e.stopPropagation();
            this.showCustomColorPicker();
        },
        /** @this ColorPickerPopover */
        'click .color-picker-backdrop': function () {
            this.close();
        },
    }

    /**
     * @param {{
     *     userId: string,
     *     currentColor?: string,
     *     targetEl: HTMLElement,
     * }} options
     */
    constructor(options) {
        super(options);
        this.options = options;
    }

    data() {
        return {
            paletteColors: this.palette.map(color => ({
                color: color,
                isSelected: color === this.selectedColor,
            })),
            customColorLabel: this.translate('Custom Color', 'labels', 'Calendar'),
        };
    }

    setup() {
        this.userId = this.options.userId;
        this.selectedColor = this.options.currentColor || null;
        this.targetEl = this.options.targetEl;

        // Load bootstrap-colorpicker for custom color option
        this.wait(Espo.loader.requirePromise('lib!bootstrap-colorpicker'));
    }

    afterRender() {
        // Position the popover relative to the target element
        this.positionPopover();

        // Close on outside click
        $(document).on('click.colorPickerPopover', (e) => {
            if (!$(e.target).closest('.color-picker-popover').length &&
                !$(e.target).closest('.colorpicker').length) {
                this.close();
            }
        });

        // Close on escape key
        $(document).on('keydown.colorPickerPopover', (e) => {
            if (e.key === 'Escape') {
                this.close();
            }
        });
    }

    /**
     * Position the popover below or above the target element
     * @private
     */
    positionPopover() {
        if (!this.targetEl) return;

        const $popover = this.$el.find('.color-picker-popover');
        const $target = $(this.targetEl);
        const targetOffset = $target.offset();
        const targetHeight = $target.outerHeight();
        const windowHeight = $(window).height();
        const popoverHeight = $popover.outerHeight();

        let top = targetOffset.top + targetHeight + 5;
        let left = targetOffset.left;

        // If popover would go below viewport, show above
        if (top + popoverHeight > windowHeight) {
            top = targetOffset.top - popoverHeight - 5;
        }

        // Ensure left doesn't go off screen
        const popoverWidth = $popover.outerWidth();
        if (left + popoverWidth > $(window).width()) {
            left = $(window).width() - popoverWidth - 10;
        }

        $popover.css({
            top: top + 'px',
            left: left + 'px',
        });
    }

    /**
     * Select a color from the palette
     * @param {string} color
     */
    selectColor(color) {
        this.selectedColor = color;
        this.trigger('select', color, this.userId);
        this.close();
    }

    /**
     * Show the full color picker for custom color
     * @private
     */
    showCustomColorPicker() {
        const $customInput = this.$el.find('.color-picker-custom-input');
        const $container = this.$el.find('.color-picker-custom-container');

        $container.removeClass('hidden');

        // Initialize bootstrap-colorpicker
        // noinspection JSUnresolvedReference
        $customInput.colorpicker({
            format: 'hex',
            container: $container,
            inline: true,
            color: this.selectedColor || '#4285f4',
            sliders: {
                saturation: {
                    maxLeft: 150,
                    maxTop: 150,
                },
                hue: {
                    maxTop: 150,
                },
            },
        });

        $customInput.on('changeColor', (e) => {
            // @ts-ignore
            const color = e.color.toHex();
            this.selectColor(color);
        });
    }

    /**
     * Close the popover
     */
    close() {
        $(document).off('click.colorPickerPopover');
        $(document).off('keydown.colorPickerPopover');
        this.trigger('close');
    }

    onRemove() {
        $(document).off('click.colorPickerPopover');
        $(document).off('keydown.colorPickerPopover');
    }
}

export default ColorPickerPopover;

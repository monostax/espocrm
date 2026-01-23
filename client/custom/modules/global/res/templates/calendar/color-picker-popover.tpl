<style>
.color-picker-popover {
    position: fixed;
    z-index: 1060;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.15);
    padding: 10px;
    min-width: 180px;
}
.color-picker-palette {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 6px;
    margin-bottom: 10px;
}
.color-picker-color {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    cursor: pointer;
    border: 2px solid transparent;
    transition: transform 0.1s, border-color 0.1s;
}
.color-picker-color:hover {
    transform: scale(1.15);
    border-color: rgba(0,0,0,0.2);
}
.color-picker-color.selected {
    border-color: #333;
}
.color-picker-divider {
    height: 1px;
    background: #eee;
    margin: 8px 0;
}
.color-picker-custom-btn {
    display: flex;
    align-items: center;
    padding: 6px 8px;
    cursor: pointer;
    border-radius: 3px;
    color: #555;
    font-size: 13px;
}
.color-picker-custom-btn:hover {
    background: #f5f5f5;
}
.color-picker-custom-btn .fas {
    margin-right: 8px;
    color: #888;
}
.color-picker-custom-container {
    margin-top: 10px;
}
.color-picker-custom-container .colorpicker {
    position: relative !important;
    top: 0 !important;
    left: 0 !important;
}
</style>
<div class="color-picker-popover">
    <div class="color-picker-palette">
        {{#each paletteColors}}
        <div
            class="color-picker-color{{#if isSelected}} selected{{/if}}"
            style="background-color: {{color}};"
            data-color="{{color}}"
            title="{{color}}"
        ></div>
        {{/each}}
    </div>
    <div class="color-picker-divider"></div>
    <div class="color-picker-custom-btn" data-action="customColor">
        <span class="fas fa-palette"></span>
        {{customColorLabel}}
    </div>
    <div class="color-picker-custom-container hidden">
        <input type="hidden" class="color-picker-custom-input">
    </div>
</div>

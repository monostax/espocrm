/**
 * Chatwoot-style scroll indicators for the document and nested scroll areas.
 * Keep the overlays outside view markup so list rerenders and table layouts
 * are unaffected. Native wheel, touch and keyboard scrolling is preserved.
 */
function initOverlayScrollbars() {
    const root = document.documentElement;

    if (root.classList.contains('vscode-scrollbars')) {
        return;
    }

    const layer = document.createElement('div');
    layer.className = 'vscode-scrollbar-layer';
    layer.setAttribute('aria-hidden', 'true');
    document.body.append(layer);
    root.classList.add('vscode-scrollbars');

    const entries = new Map();
    let pointer = null;
    let frame = null;

    const scheduleUpdate = () => {
        if (frame === null) {
            frame = requestAnimationFrame(update);
        }
    };

    const resizeObserver = new ResizeObserver(scheduleUpdate);
    resizeObserver.observe(root);
    resizeObserver.observe(document.body);

    const getBounds = element => {
        if (element === document.scrollingElement) {
            return {left: 0, top: 0, width: window.innerWidth, height: window.innerHeight};
        }

        const rect = element.getBoundingClientRect();

        return {
            left: rect.left + element.clientLeft,
            top: rect.top + element.clientTop,
            width: element.clientWidth,
            height: element.clientHeight,
        };
    };

    const createEntry = element => {
        const track = document.createElement('div');
        track.className = 'vscode-scrollbar-track';

        const vertical = document.createElement('div');
        const horizontal = document.createElement('div');
        vertical.className = 'vscode-scrollbar-thumb vscode-scrollbar-thumb-vertical';
        horizontal.className = 'vscode-scrollbar-thumb vscode-scrollbar-thumb-horizontal';
        track.append(vertical, horizontal);
        layer.append(track);
        resizeObserver.observe(element);

        // Establish the transparent starting state before the first fade-in.
        getComputedStyle(track).opacity;

        const entry = {track, vertical, horizontal, removeTimer: null};
        entries.set(element, entry);

        return entry;
    };

    const positionThumb = (thumb, size, scrollSize, scrollPosition, enabled, vertical, rtl) => {
        thumb.hidden = !enabled || size <= 0 || scrollSize <= size;

        if (thumb.hidden) {
            return;
        }

        const length = Math.min(size, Math.max(size * size / scrollSize, 24));
        const progress = Math.min(1, Math.max(0, Math.abs(scrollPosition) / (scrollSize - size)));
        const offset = (rtl ? 1 - progress : progress) * (size - length);

        thumb.style[vertical ? 'height' : 'width'] = `${length}px`;
        thumb.style.transform = vertical ? `translateY(${offset}px)` : `translateX(${offset}px)`;
    };

    function update() {
        frame = null;

        const active = new Set();
        let element = pointer ? document.elementFromPoint(pointer.x, pointer.y) : null;

        for (; element; element = element.parentElement) {
            const style = getComputedStyle(element);
            const isRoot = element === document.scrollingElement;
            const bodyStyle = isRoot ? getComputedStyle(document.body) : null;
            const overflowX = isRoot && style.overflowX === 'visible' ? bodyStyle.overflowX : style.overflowX;
            const overflowY = isRoot && style.overflowY === 'visible' ? bodyStyle.overflowY : style.overflowY;
            const canScroll = overflow => /^(auto|scroll|overlay)$/.test(overflow) ||
                (isRoot && overflow === 'visible');
            const bounds = getBounds(element);
            const horizontal = canScroll(overflowX) && element.scrollWidth > bounds.width;
            const vertical = canScroll(overflowY) && element.scrollHeight > bounds.height;

            if (!horizontal && !vertical) {
                continue;
            }

            active.add(element);

            const entry = entries.get(element) || createEntry(element);
            clearTimeout(entry.removeTimer);
            entry.removeTimer = null;

            const {track} = entry;
            Object.assign(track.style, {
                left: `${bounds.left}px`,
                top: `${bounds.top}px`,
                width: `${bounds.width}px`,
                height: `${bounds.height}px`,
                opacity: '1',
            });

            // A nested scrollbar must be clipped by the same ancestors as its content.
            const clip = {
                left: bounds.left,
                top: bounds.top,
                right: bounds.left + bounds.width,
                bottom: bounds.top + bounds.height,
            };

            for (let parent = element.parentElement; parent && parent !== root; parent = parent.parentElement) {
                const parentStyle = getComputedStyle(parent);
                const parentBounds = getBounds(parent);

                if (parentStyle.overflowX !== 'visible') {
                    clip.left = Math.max(clip.left, parentBounds.left);
                    clip.right = Math.min(clip.right, parentBounds.left + parentBounds.width);
                }

                if (parentStyle.overflowY !== 'visible') {
                    clip.top = Math.max(clip.top, parentBounds.top);
                    clip.bottom = Math.min(clip.bottom, parentBounds.top + parentBounds.height);
                }
            }

            track.style.clipPath = `inset(${clip.top - bounds.top}px ` +
                `${bounds.left + bounds.width - clip.right}px ` +
                `${bounds.top + bounds.height - clip.bottom}px ${clip.left - bounds.left}px)`;

            positionThumb(entry.vertical, bounds.height, element.scrollHeight, element.scrollTop, vertical, true);
            positionThumb(entry.horizontal, bounds.width, element.scrollWidth, element.scrollLeft,
                horizontal, false, style.direction === 'rtl');
        }

        for (const [element, entry] of entries) {
            if (active.has(element) || entry.removeTimer !== null) {
                continue;
            }

            entry.track.style.opacity = '0';
            entry.removeTimer = setTimeout(() => {
                entry.track.remove();
                if (element !== root && element !== document.body) {
                    resizeObserver.unobserve(element);
                }
                entries.delete(element);
            }, 300);
        }
    }

    document.addEventListener('pointerover', event => {
        if (event.pointerType === 'touch') {
            return;
        }

        pointer = {x: event.clientX, y: event.clientY};
        scheduleUpdate();
    }, {passive: true});

    document.addEventListener('pointermove', event => {
        if (event.pointerType === 'touch') {
            return;
        }

        if (!pointer) {
            scheduleUpdate();
        }

        pointer = {x: event.clientX, y: event.clientY};
    }, {passive: true});

    document.addEventListener('pointerout', event => {
        if (!event.relatedTarget) {
            pointer = null;
            scheduleUpdate();
        }
    }, {passive: true});

    document.addEventListener('scroll', scheduleUpdate, {capture: true, passive: true});
    document.addEventListener('load', scheduleUpdate, true);
    window.addEventListener('resize', scheduleUpdate, {passive: true});
    window.addEventListener('blur', () => {
        pointer = null;
        scheduleUpdate();
    });

    const mutationObserver = new MutationObserver(mutations => {
        if (mutations.some(mutation => !layer.contains(mutation.target))) {
            scheduleUpdate();
        }
    });

    mutationObserver.observe(root, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class', 'style', 'hidden', 'open'],
        characterData: true,
    });
}

export default initOverlayScrollbars;

/**
 * Custom field view for displaying external avatar images from Chatwoot.
 * Renders the avatar as an image in list/detail views, falls back to initials.
 */

import UrlFieldView from 'views/fields/url';

class AvatarUrlFieldView extends UrlFieldView {

    listTemplate = 'chatwoot:chatwoot-contact/fields/avatar-url/list'
    detailTemplate = 'chatwoot:chatwoot-contact/fields/avatar-url/detail'

    data() {
        const data = super.data();
        
        data.avatarUrl = this.model.get(this.name);
        data.contactName = this.model.get('name') || 'Unknown';
        data.initials = this.getInitials(data.contactName);
        data.initialsColor = this.getInitialsColor(data.contactName);
        
        return data;
    }

    /**
     * Get initials from a name (first letter of first and last name)
     * @param {string} name Contact name
     * @returns {string} Initials (1-2 characters)
     */
    getInitials(name) {
        if (!name) return '?';
        
        const parts = name.trim().split(/\s+/);
        
        if (parts.length === 1) {
            return parts[0].charAt(0).toUpperCase();
        }
        
        return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
    }

    /**
     * Generate a consistent color based on the name
     * @param {string} name Contact name
     * @returns {string} HSL color string
     */
    getInitialsColor(name) {
        if (!name) return 'hsl(0, 0%, 60%)';
        
        // Generate hash from name
        let hash = 0;
        for (let i = 0; i < name.length; i++) {
            hash = name.charCodeAt(i) + ((hash << 5) - hash);
        }
        
        // Convert to hue (0-360)
        const hue = Math.abs(hash % 360);
        
        return `hsl(${hue}, 65%, 45%)`;
    }

    afterRender() {
        super.afterRender();
        
        // Handle image load errors by showing initials fallback
        const $img = this.$el.find('.chatwoot-avatar-img');
        
        if ($img.length) {
            $img.on('error', () => {
                $img.hide();
                this.$el.find('.chatwoot-avatar-initials').show();
            });
        }
    }
}

export default AvatarUrlFieldView;


define(['views/fields/url'], Dep => {
    return class extends Dep {
        detailTemplate = 'feature-voip:call/fields/recording-url/detail';
        listTemplate = 'feature-voip:call/fields/recording-url/detail';
        listLinkTemplate = 'feature-voip:call/fields/recording-url/detail';

        data() {
            const data = super.data();
            const value = this.model.get(this.name);
            const id = this.model.id;

            data.streamUrl = value && id ? `api/v1/VoipRecording/${encodeURIComponent(id)}/stream` : null;
            data.rawUrl = value || null;

            return data;
        }
    };
});

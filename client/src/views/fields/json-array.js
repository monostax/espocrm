/************************************************************************
 * Client view for field type jsonArray (Espo defaults to this path;
 * core only ships json-object). Read-only pretty JSON display.
 ************************************************************************/

import BaseFieldView from 'views/fields/base';

class JsonArrayFieldView extends BaseFieldView {

    type = 'jsonArray'

    listTemplate = 'fields/json-object/detail'
    detailTemplate = 'fields/json-object/detail'
    editTemplate = 'fields/json-object/detail'

    data() {
        const data = super.data();

        data.valueIsSet = this.model.has(this.name);
        data.isNotEmpty = !!this.model.get(this.name);

        return data;
    }

    getValueForDisplay() {
        const value = this.model.get(this.name);

        if (value === null || value === undefined) {
            return null;
        }

        return JSON.stringify(value, null, 2)
            .replace(/(\r\n|\n|\r)/gm, '<br>').replace(/\s/g, '&nbsp;');
    }

    fetch() {
        // read-only storage type; keep existing model value
        return {};
    }
}

export default JsonArrayFieldView;

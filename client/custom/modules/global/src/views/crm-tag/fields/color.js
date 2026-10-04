import ColorpickerField from "views/fields/colorpicker";
import {tagHex} from "global:crm-tags";

export default class TagColorField extends ColorpickerField {
    data() {
        const data = super.data();
        data.value = tagHex(data.value) || "";
        return data;
    }
}

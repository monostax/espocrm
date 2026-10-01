import Collection from 'collection';

export default class extends Collection {
    prepareAttributes(response, options) {
        this.canCreate = response.canCreate === true;
        return super.prepareAttributes(response, options);
    }
}

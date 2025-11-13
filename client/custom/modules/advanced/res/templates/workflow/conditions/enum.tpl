
{{#if readOnly}}
    <span class="comparison">
        {{translate comparisonValue category='labels' scope='Workflow'}}
    </span>

    <span class="subject-type">
        {{{subjectType}}}
    </span>

    <span class="subject">
        {{{subject}}}
    </span>
{{else}}
    <div class="row">
        <div class="col-sm-6 comparison">
            <span data-field="comparison">{{{comparisonField}}}</span>
        </div>
    </div>
    <div class="row">
        <div class="col-sm-12 subject">
            {{{subject}}}
        </div>
    </div>
{{/if}}

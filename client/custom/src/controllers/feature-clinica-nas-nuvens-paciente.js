define("controllers/feature-clinica-nas-nuvens-paciente", ["controllers/record"], (Dep) => {
    return class extends Dep {
        entityType = "FeatureIntegrationClinicaNasNuvensPaciente";
    };
});

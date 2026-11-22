// Android release builds run a "lint vital" check on every library. The Stripe SDK's lint
// classpath asks for com.google.android.gms:play-services-tapandpay, which Google doesn't publish
// publicly (it's only for push provisioning, which Panmotive doesn't use), so the check can't
// resolve and the whole build fails. Lint isn't needed to build the app: skip those tasks.
const { withProjectBuildGradle } = require('expo/config-plugins');

const MARK = '// panmotive: skip release lint (see plugins/withoutReleaseLint.js)';

module.exports = function withoutReleaseLint(config) {
  return withProjectBuildGradle(config, (cfg) => {
    if (!cfg.modResults.contents.includes(MARK)) {
      cfg.modResults.contents += `
${MARK}
subprojects {
    tasks.configureEach { task ->
        if (task.name.toLowerCase().contains('lintvital') || task.name.toLowerCase().contains('lintanalyze')) {
            task.enabled = false
        }
    }
}
`;
    }
    return cfg;
  });
};

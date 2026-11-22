// The Stripe SDK declares (compileOnly) Stripe's card-issuing "push provisioning" library, which in
// turn needs com.google.android.gms:play-services-tapandpay — a library Google doesn't publish
// publicly. Panmotive never uses push provisioning (it's for card issuers adding cards to Google
// Wallet; Stripe reaches it by reflection, so it's optional), but Gradle still tries to download it
// and the Android build fails. Leave it out everywhere.
const { withProjectBuildGradle } = require('expo/config-plugins');

const MARK = '// panmotive: no play-services-tapandpay (see plugins/withoutReleaseLint.js)';

module.exports = function withoutTapAndPay(config) {
  return withProjectBuildGradle(config, (cfg) => {
    if (!cfg.modResults.contents.includes(MARK)) {
      cfg.modResults.contents += `
${MARK}
allprojects {
    configurations.configureEach {
        exclude group: 'com.google.android.gms', module: 'play-services-tapandpay'
    }
}
`;
    }
    return cfg;
  });
};

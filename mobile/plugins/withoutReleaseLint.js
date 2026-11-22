// The Stripe SDK declares (compileOnly) Stripe's card-issuing "push provisioning" library, which in
// turn needs com.google.android.gms:play-services-tapandpay — a library Google doesn't publish
// publicly. Panmotive never uses push provisioning (it's for card issuers adding cards to Google
// Wallet; Stripe reaches it by reflection, so it's optional). So:
//  1. leave tapandpay out of every Gradle configuration (otherwise the build can't resolve it), and
//  2. tell R8 (the release shrinker) those optional classes are meant to be missing.
const fs = require('fs');
const path = require('path');
const { withDangerousMod, withProjectBuildGradle } = require('expo/config-plugins');

const MARK = '// panmotive: no play-services-tapandpay (see plugins/withoutReleaseLint.js)';
const RULES = `
# panmotive: Stripe push provisioning is optional and not included (see plugins/withoutReleaseLint.js)
-dontwarn com.stripe.android.pushProvisioning.**
-dontwarn com.google.android.gms.tapandpay.**
`;

module.exports = function withoutTapAndPay(config) {
  config = withProjectBuildGradle(config, (cfg) => {
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

  return withDangerousMod(config, [
    'android',
    (cfg) => {
      const file = path.join(cfg.modRequest.platformProjectRoot, 'app', 'proguard-rules.pro');
      const now = fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : '';
      if (!now.includes('panmotive: Stripe push provisioning')) fs.writeFileSync(file, now + RULES);
      return cfg;
    },
  ]);
};

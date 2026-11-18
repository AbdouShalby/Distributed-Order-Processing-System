// Run only against an isolated disposable demo database, with no other load.
// Reset order (destructive; never use a shared/live database or Redis):
// 1. php artisan migrate:fresh --seed
// 2. php artisan db:seed --class=RefundDemoSeeder (copy its printed order ID)
// 3. redis-cli FLUSHALL (the isolated demo Redis only)
// 4. Run alone: k6 run -e BASE_URL=http://localhost:80 -e AGENT_TOKEN=<local-token> \
//      -e CUSTOMER_ID=1 -e ORDER_ID=<printed-id> load-tests/refund-race-test.js
// The token must match AGENT_SERVICE_TOKEN; the order must be fresh and PAID.

import http from 'k6/http';
import { Counter } from 'k6/metrics';

const BASE_URL = (__ENV.BASE_URL || 'http://localhost:80').replace(/\/$/, '');
const AGENT_TOKEN = __ENV.AGENT_TOKEN;
const CUSTOMER_ID = __ENV.CUSTOMER_ID || '1';
const ORDER_ID = __ENV.ORDER_ID;

if (!ORDER_ID || !/^[1-9]\d*$/.test(ORDER_ID)) {
    throw new Error('ORDER_ID must be the positive order ID printed by RefundDemoSeeder.');
}
if (!AGENT_TOKEN) {
    throw new Error('AGENT_TOKEN must match the isolated demo service token.');
}

const refundsAccepted = new Counter('refunds_accepted');
const refundsExceed = new Counter('refunds_exceed');
const unexpected = new Counter('unexpected');

export const options = {
    scenarios: {
        refund_race: {
            executor: 'shared-iterations',
            vus: 50,
            iterations: 50,
            maxDuration: '30s',
        },
    },
    thresholds: {
        refunds_accepted: ['count==10'],
        refunds_exceed: ['count==40'],
        unexpected: ['count==0'],
    },
};

export default function () {
    // Emit zero samples so absent outcomes still exist for the thresholds.
    refundsAccepted.add(0);
    refundsExceed.add(0);
    unexpected.add(0);

    const response = http.post(`${BASE_URL}/api/agent/orders/${ORDER_ID}/refunds`, JSON.stringify({
        amount: '10.00',
        reason: 'k6 race',
        idempotency_key: `race-${__VU}-${__ITER}-${Date.now()}`,
        mode: 'needs_approval',
    }), {
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Service-Token': AGENT_TOKEN,
            'X-Customer-Id': CUSTOMER_ID,
        },
    });

    let errorCode;
    if (response.status === 422) {
        try {
            errorCode = response.json('error_code');
        } catch (_) {
            // A malformed response is unexpected, even when its HTTP code is 422.
        }
    }

    if (response.status === 201) {
        refundsAccepted.add(1);
    } else if (response.status === 422 && errorCode === 'REFUND_EXCEEDS_PAID') {
        refundsExceed.add(1);
    } else {
        unexpected.add(1);
        console.log(`VU ${__VU}: unexpected refund response ${response.status}`);
    }
}

export function handleSummary(data) {
    const accepted = data.metrics.refunds_accepted ? data.metrics.refunds_accepted.values.count : 0;
    const exceed = data.metrics.refunds_exceed ? data.metrics.refunds_exceed.values.count : 0;
    const other = data.metrics.unexpected ? data.metrics.unexpected.values.count : 0;
    const verdict = accepted === 10 && exceed === 40 && other === 0 ? 'PASS' : 'FAIL';

    return {
        stdout: `Refund race results\nrefunds_accepted: ${accepted} (expected 10)\n` +
            `refunds_exceed: ${exceed} (expected 40)\nunexpected: ${other} (expected 0)\nVerdict: ${verdict}\n`,
    };
}

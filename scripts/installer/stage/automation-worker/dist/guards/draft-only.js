import { draftOnlyAction } from '../contracts/api.js';
export function assertDraftOnly(action) { if (action !== draftOnlyAction)
    throw new Error('DANGEROUS_ACTION_BLOCKED'); }

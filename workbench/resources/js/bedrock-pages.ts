import { createBedrockResolver } from '@frolax/bedrock/react';
import type { ResolvedPage } from '@frolax/bedrock/react';
import 'virtual:bedrock-overrides';

const pages = import.meta.glob<ResolvedPage>('./pages/**/*.tsx');

export const resolveBedrockPage = createBedrockResolver({
    local(name) {
        const page = pages[`./pages/${name}.tsx`];

        if (!page) throw new Error(`Page [${name}] was not found.`);

        return page();
    },
});

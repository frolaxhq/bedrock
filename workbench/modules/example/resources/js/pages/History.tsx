import { Head, Link } from '@inertiajs/react';
import { IconArrowLeft } from '@tabler/icons-react';
import { AppLayout, Button } from '@frolax/bedrock/ui';
import '../../css/module.css';

export default function History() {
    return (
        <AppLayout title="Example History">
            <main data-bedrock-module className="mx-auto max-w-5xl p-6">
                <Head title="Example History" />
                <section className="rounded-xl border border-border bg-card p-6 text-card-foreground shadow-sm">
                    <h1 className="text-3xl font-semibold tracking-tight">Example history</h1>
                    <p className="mt-2 text-muted-foreground">
                        A nested module page rendered through the module federation runtime.
                    </p>
                    <Button className="mt-6" render={<Link href="/example" />}>
                        <IconArrowLeft /> Back to the module
                    </Button>
                </section>
            </main>
        </AppLayout>
    );
}

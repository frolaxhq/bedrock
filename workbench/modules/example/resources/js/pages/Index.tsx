import { Head, Link } from '@inertiajs/react';
import { IconArrowLeft, IconBox } from '@tabler/icons-react';
import { AppLayout, Button } from '@frolax/bedrock/ui';
import '../../css/module.css';

interface Props {
    message: string;
}

export default function Index({ message }: Props) {
    return (
        <AppLayout title="Bedrock Example">
            <main data-bedrock-module className="mx-auto max-w-5xl p-6">
                <Head title="Bedrock Example" />
                <section className="rounded-xl border border-border bg-card p-6 text-card-foreground shadow-sm">
                    <span className="inline-flex items-center gap-2 rounded-full bg-primary/10 px-3 py-1 text-sm font-medium text-primary">
                        <IconBox /> Module Federation
                    </span>
                    <h1 className="mt-4 text-3xl font-semibold tracking-tight">
                        Bedrock full-stack module
                    </h1>
                    <p className="mt-2 text-muted-foreground">{message}</p>
                    <div className="mt-6 flex gap-3">
                        <Button render={<Link href="/example/history" />}>View history</Button>
                        <Button variant="outline" render={<Link href="/" />}>
                            <IconArrowLeft /> Return to the host
                        </Button>
                    </div>
                </section>
            </main>
        </AppLayout>
    );
}

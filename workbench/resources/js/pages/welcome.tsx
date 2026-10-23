import { IconBox, IconMoonStars } from '@tabler/icons-react';
import {
    Button,
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    ThemeToggle,
} from '@frolax/bedrock/ui';
import { AppLayout } from '@frolax/bedrock/ui';

export default function Welcome() {
    return (
        <AppLayout title="Bedrock workbench">
            <main className="mx-auto grid min-h-screen max-w-5xl content-center gap-6 p-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <p className="text-sm font-medium text-primary">Bedrock workbench</p>
                        <h1 className="text-3xl font-semibold tracking-tight">
                            Convention overrides with live HMR
                        </h1>
                    </div>
                    <ThemeToggle />
                </div>
                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <IconBox /> Shared UI
                            </CardTitle>
                            <CardDescription>
                                This button comes from the host convention override.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button>Host override</Button>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <IconMoonStars /> Theme tokens
                            </CardTitle>
                            <CardDescription>
                                Host CSS variables apply to Bedrock, host, and module UI.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                </div>
            </main>
        </AppLayout>
    );
}

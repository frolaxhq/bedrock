import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { AppLayout, AuthLayout, Button, Input, Label, ThemeToggle } from '@frolax/bedrock/ui';
import type { ResolvedPage } from '../runtime/types.js';

type Field = { name: string; label: string; type?: string; autoComplete?: string };
const email: Field = {
    name: 'email',
    label: 'Email address',
    type: 'email',
    autoComplete: 'email',
};
const password: Field = {
    name: 'password',
    label: 'Password',
    type: 'password',
    autoComplete: 'current-password',
};
const newPassword: Field = { ...password, autoComplete: 'new-password' };
const confirmation: Field = {
    name: 'password_confirmation',
    label: 'Confirm password',
    type: 'password',
    autoComplete: 'new-password',
};

function Status() {
    const status = usePage().props.status;
    return typeof status === 'string' && status ? (
        <p role="status" className="mb-5 rounded-lg border border-border bg-muted p-3 text-sm">
            {status}
        </p>
    ) : null;
}

function AuthPage({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    const props = usePage().props;
    const name = typeof props.name === 'string' ? props.name : 'Bedrock';
    return (
        <AuthLayout
            header={
                <div className="flex items-center justify-between p-6">
                    <div className="flex items-center gap-3 font-semibold">
                        {typeof props.brandLogo === 'string' && (
                            <img src={props.brandLogo} alt="" className="size-8 object-contain" />
                        )}
                        {name}
                    </div>
                    <ThemeToggle />
                </div>
            }
        >
            <Head title={title} />
            <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
            <p className="mb-7 mt-2 text-sm text-muted-foreground">{description}</p>
            <Status />
            {children}
        </AuthLayout>
    );
}

function Form({
    fields,
    action,
    method = 'post',
    initial = {},
    submit,
    children,
}: {
    fields: Field[];
    action: string;
    method?: 'post' | 'patch' | 'put';
    initial?: Record<string, string | boolean>;
    submit: string;
    children?: ReactNode;
}) {
    const defaults: Record<string, string | boolean> = { ...initial };
    for (const field of fields) defaults[field.name] ??= '';
    const form = useForm(defaults);
    function send(event: FormEvent) {
        event.preventDefault();
        form.submit(method, action, {
            preserveScroll: true,
            onSuccess: () => {
                for (const field of fields)
                    if (field.type === 'password') form.setData(field.name, '');
            },
        });
    }
    return (
        <form onSubmit={send} className="space-y-5">
            {fields.map((field) => (
                <div key={field.name} className="space-y-2">
                    <Label htmlFor={`${submit}-${field.name}`}>{field.label}</Label>
                    <Input
                        id={`${submit}-${field.name}`}
                        name={field.name}
                        type={field.type ?? 'text'}
                        autoComplete={field.autoComplete}
                        required
                        value={String(form.data[field.name] ?? '')}
                        onChange={(event) => form.setData(field.name, event.target.value)}
                        aria-invalid={Boolean(form.errors[field.name])}
                        aria-describedby={
                            form.errors[field.name] ? `${submit}-${field.name}-error` : undefined
                        }
                    />
                    {form.errors[field.name] && (
                        <p
                            id={`${submit}-${field.name}-error`}
                            role="alert"
                            className="text-sm text-destructive"
                        >
                            {form.errors[field.name]}
                        </p>
                    )}
                </div>
            ))}
            {Object.entries(form.errors)
                .filter(([key]) => !fields.some((field) => field.name === key))
                .map(([key, message]) => (
                    <p role="alert" key={key} className="text-sm text-destructive">
                        {String(message)}
                    </p>
                ))}
            {action === '/login' && (
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        name="remember"
                        checked={Boolean(form.data.remember)}
                        onChange={(event) => form.setData('remember', event.target.checked)}
                    />
                    Remember me
                </label>
            )}
            <Button type="submit" disabled={form.processing}>
                {form.processing ? 'Please wait…' : submit}
            </Button>
            {children}
        </form>
    );
}

export function Login() {
    const registration = usePage().props.registration;
    return (
        <AuthPage title="Welcome back" description="Sign in to your account to continue.">
            <Form fields={[email, password]} action="/login" submit="Sign in" />
            <div className="mt-6 flex justify-between gap-4 text-sm">
                <Link className="underline underline-offset-4" href="/forgot-password">
                    Forgot password?
                </Link>
                {registration === true && (
                    <Link className="underline underline-offset-4" href="/register">
                        Create an account
                    </Link>
                )}
            </div>
        </AuthPage>
    );
}
export function Register() {
    return (
        <AuthPage title="Create an account" description="Enter your details to get started.">
            <Form
                fields={[
                    { name: 'name', label: 'Name', autoComplete: 'name' },
                    email,
                    newPassword,
                    confirmation,
                ]}
                action="/register"
                submit="Create account"
            />
            <p className="mt-6 text-sm">
                Already registered?{' '}
                <Link href="/login" className="underline">
                    Sign in
                </Link>
            </p>
        </AuthPage>
    );
}
export function ForgotPassword() {
    return (
        <AuthPage
            title="Reset your password"
            description="We’ll send a reset link to your email address."
        >
            <Form fields={[email]} action="/forgot-password" submit="Send reset link" />
            <Link className="mt-6 block text-sm underline" href="/login">
                Back to sign in
            </Link>
        </AuthPage>
    );
}
export function ResetPassword() {
    const props = usePage().props;
    return (
        <AuthPage title="Choose a new password" description="Set a new password for your account.">
            <Form
                fields={[email, newPassword, confirmation]}
                initial={{
                    token: typeof props.token === 'string' ? props.token : '',
                    email: typeof props.email === 'string' ? props.email : '',
                }}
                action="/reset-password"
                submit="Reset password"
            />
        </AuthPage>
    );
}
export function VerifyEmail() {
    return (
        <AuthPage
            title="Verify your email"
            description="Open the link in your email to verify your address."
        >
            <Form
                fields={[]}
                action="/email/verification-notification"
                submit="Resend verification email"
            />
            <div className="mt-6 flex gap-5 text-sm">
                <Link href="/profile" className="underline">
                    Edit email address
                </Link>
                <Link href="/logout" method="post" as="button" className="underline">
                    Log out
                </Link>
            </div>
        </AuthPage>
    );
}
export function Profile() {
    const profile = usePage().props.profile as { name: string; email: string; verified: boolean };
    return (
        <AppLayout title="Your profile">
            <div className="mx-auto w-full max-w-2xl space-y-8 p-6">
                <div>
                    <h1 className="text-2xl font-semibold">Your profile</h1>
                    <p className="mt-2 text-muted-foreground">
                        Manage your account details and password.
                    </p>
                </div>
                <Status />
                <section className="rounded-xl border border-border p-6">
                    <h2 className="mb-5 text-lg font-medium">Account details</h2>
                    <Form
                        fields={[{ name: 'name', label: 'Name', autoComplete: 'name' }, email]}
                        initial={{ name: profile.name, email: profile.email }}
                        action="/profile"
                        method="patch"
                        submit="Save profile"
                    />
                    {!profile.verified && (
                        <Link className="mt-4 block text-sm underline" href="/verify-email">
                            Verify your email address
                        </Link>
                    )}
                </section>
                <section className="rounded-xl border border-border p-6">
                    <h2 className="mb-5 text-lg font-medium">Change password</h2>
                    <Form
                        fields={[
                            { ...password, name: 'current_password', label: 'Current password' },
                            newPassword,
                            confirmation,
                        ]}
                        action="/password"
                        method="put"
                        submit="Update password"
                    />
                </section>
            </div>
        </AppLayout>
    );
}
export function Dashboard() {
    const name = usePage().props.name;
    return (
        <AppLayout title="Dashboard">
            <div className="mx-auto w-full max-w-5xl p-6 lg:p-10">
                <p className="text-sm font-medium text-muted-foreground">Workspace</p>
                <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                    Welcome to {typeof name === 'string' ? name : 'Bedrock'}
                </h1>
                <p className="mt-3 max-w-xl text-muted-foreground">
                    Your application is ready. Use the navigation to open your project’s features,
                    or manage your account below.
                </p>
                <div className="mt-8 rounded-xl border border-border p-6">
                    <h2 className="font-medium">Make yourself at home</h2>
                    <p className="mb-5 mt-2 text-sm text-muted-foreground">
                        Keep your profile up to date and choose the appearance that works for you.
                    </p>
                    <Link
                        href="/profile"
                        className="text-sm font-medium underline underline-offset-4"
                    >
                        Manage your profile
                    </Link>
                </div>
                <Status />
            </div>
        </AppLayout>
    );
}

const pages = { Login, Register, ForgotPassword, ResetPassword, VerifyEmail, Profile, Dashboard };
export function resolveFoundationPage(name: string): ResolvedPage {
    const key = name.replace(/^bedrock\//, '') as keyof typeof pages;
    if (!name.startsWith('bedrock/') || !Object.hasOwn(pages, key))
        throw new Error(`Page [${name}] was not found.`);
    return { default: pages[key] };
}

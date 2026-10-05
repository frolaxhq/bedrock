import { ButtonPrimitive } from '@frolax/bedrock/primitives';
import { cn, cva, type VariantProps } from '@frolax/bedrock/lib';

export const buttonVariants = cva(
    'inline-flex items-center justify-center rounded-lg text-sm font-medium transition-colors outline-none focus-visible:ring-3 focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50',
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground shadow-sm hover:bg-primary/85',
                outline: 'border border-border bg-background hover:bg-muted',
                secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
                ghost: 'hover:bg-muted',
                destructive: 'bg-destructive text-white hover:bg-destructive/85',
                link: 'text-primary underline-offset-4 hover:underline',
            },
            size: {
                default: 'h-9 gap-2 px-4',
                xs: 'h-6 gap-1 px-2 text-xs',
                sm: 'h-8 gap-1.5 px-3',
                lg: 'h-10 gap-2 px-5',
                icon: 'size-9',
                'icon-xs': 'size-6',
                'icon-sm': 'size-8',
                'icon-lg': 'size-10',
            },
        },
        defaultVariants: { variant: 'default', size: 'default' },
    },
);

export function Button({ className, variant, size, ...props }: ButtonPrimitive.Props & VariantProps<typeof buttonVariants>) {
    return <ButtonPrimitive className={cn(buttonVariants({ variant, size }), className)} {...props} />;
}

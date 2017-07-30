import bleach
import functools
import markdown
import threading

storage = threading.local()

def raw_markdown(s):
    md = getattr(storage, 'markdown', None)
    if md is None:
        md = storage.markdown = markdown.Markdown(output_format = 'html5')
    return md.reset().convert(s)

allowed_tags = bleach.sanitizer.ALLOWED_TAGS + [
    'h3', 'h4', 'p', 'code', 'img', 'pre'
]
allowed_attributes = {**bleach.sanitizer.ALLOWED_ATTRIBUTES,
    'img': {'alt', 'src'}
}

def create_linkify_filter():
    return functools.partial(bleach.linkifier.LinkifyFilter,
            callbacks = [bleach.callbacks.nofollow,
                         bleach.callbacks.target_blank],
            skip_tags = ['pre'])

def clean(s):
    cl = getattr(storage, 'cleaner', None)
    if cl is None:
        cl = storage.cleaner = bleach.sanitizer.Cleaner(
                tags = allowed_tags,
                attributes = allowed_attributes,
                filters = [create_linkify_filter()])
    return cl.clean(s)

def safe_markdown(s):
    return clean(raw_markdown(s))

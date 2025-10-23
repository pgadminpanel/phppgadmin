## Subresource Integrity

If you are loading Highlight.js via CDN you may wish to use [Subresource Integrity](https://developer.mozilla.org/en-US/docs/Web/Security/Subresource_Integrity) to guarantee that you are using a legimitate build of the library.

To do this you simply need to add the `integrity` attribute for each JavaScript file you download via CDN. These digests are used by the browser to confirm the files downloaded have not been modified.

```html
<script
  src="//cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/highlight.min.js"
  integrity="sha384-5xdYoZ0Lt6Jw8GFfRP91J0jaOVUq7DGI1J5wIyNi0D+eHVdfUwHR4gW6kPsw489E"></script>
<!-- including any other grammars you might need to load -->
<script
  src="//cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/languages/go.min.js"
  integrity="sha384-HdearVH8cyfzwBIQOjL/6dSEmZxQ5rJRezN7spps8E7iu+R6utS8c2ab0AgBNFfH"></script>
```

The full list of digests for every file can be found below.

### Digests

```
sha384-h5xac5UEvgYjdWtd9ajSAtkfHjGTrsb+AfrzOpoAbNLkYaPqOdY1YAb459pAkdhB /es/languages/pgsql.js
sha384-e9t/475eGSjNyO/O9vfEZHxdm21L6W2ZNlupK8+ejvTDnKDq96GjpdXw0z8/P++J /es/languages/pgsql.min.js
sha384-EsaYwDVBonFGod8SpVtelwhaj6/8fG+8zAjciAJPe1DF1eqINj15Ulw8uQtazbcj /languages/pgsql.js
sha384-MBJTfvMpjn9gjGPo9ywtyx6TJL2DqDdoAzzg1z2kWnf9UAsiE6AI2MBDBCL3zQ+X /languages/pgsql.min.js
sha384-utgmqJbt9LxuYto2iEXpVH5kyp/giOCx/zk5QQg2M8YDkq/fvIJvpg2iv/KnliDF /highlight.js
sha384-h1jSSW4qYT3MJfu7lf4pXDte8QPOxcJeWohVG/YaMEm+RWKfCEHrQvgsR4QQE+JX /highlight.min.js
```

